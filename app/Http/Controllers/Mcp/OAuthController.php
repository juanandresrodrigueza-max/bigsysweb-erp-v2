<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\McpCliente;
use App\Models\McpConexion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

// Fase 28.1: OAuth 2.1 para que Claude, ChatGPT u otro cliente MCP se conecten "con un clic": registro dinámico de clientes,
// autorización con PKCE (S256) y la pantalla de consentimiento donde el usuario elige el modo de la conexión.
class OAuthController extends Controller
{
    public const HORAS_TOKEN = 8;

    public function recurso()
    {
        return response()->json(['resource' => url('/mcp'), 'authorization_servers' => [url('/')], 'bearer_methods_supported' => ['header'], 'scopes_supported' => ['mcp'], 'resource_name' => 'BigSys ERP']);
    }

    public function servidor()
    {
        return response()->json(['issuer' => url('/'), 'authorization_endpoint' => url('/oauth/authorize'), 'token_endpoint' => url('/oauth/token'), 'registration_endpoint' => url('/oauth/register'),
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'], 'scopes_supported' => ['mcp']]);
    }

    public function registrar(Request $r)
    {
        $uris = array_values(array_filter((array) $r->input('redirect_uris', []), 'is_string'));
        if (! $uris || count($uris) > 10) return $this->falla('invalid_redirect_uri', 'Faltan las redirect_uris.');
        foreach ($uris as $u) if (! self::uriValida($u)) return $this->falla('invalid_redirect_uri', "La dirección {$u} no es válida (tiene que ser https o localhost).");
        $c = McpCliente::create(['client_id' => 'mcp_' . Str::random(32), 'nombre' => mb_substr((string) ($r->input('client_name') ?: 'Cliente MCP'), 0, 150), 'redirect_uris' => $uris]);
        return response()->json(['client_id' => $c->client_id, 'client_name' => $c->nombre, 'redirect_uris' => $uris, 'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'client_id_issued_at' => $c->created_at->timestamp], 201);
    }

    public static function uriValida(string $u): bool
    {
        $p = parse_url($u); if (! $p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment'])) return false;
        return $p['scheme'] === 'https' || ($p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    // Pantalla de consentimiento (el usuario ya inició sesión: lo garantiza el middleware auth).
    public function autorizar(Request $r)
    {
        [$cli, $err] = $this->validarPedido($r);
        $u = $r->user();
        if ($err) return Inertia::render('Mcp/Autorizar', ['error' => $err]);
        if ($u->is_superadmin) return Inertia::render('Mcp/Autorizar', ['error' => 'El superadministrador no puede conectar una IA. Entrá con un usuario de la empresa.']);
        return Inertia::render('Mcp/Autorizar', ['cliente' => $cli->nombre, 'host' => parse_url($r->query('redirect_uri'), PHP_URL_HOST), 'empresa' => $u->business?->name, 'usuario' => $u->name,
            'modos' => McpConexion::MODOS, 'params' => $r->only('response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope')]);
    }

    public function decidir(Request $r)
    {
        [$cli, $err] = $this->validarPedido($r);
        abort_if($err, 422, $err);
        $u = $r->user(); abort_if($u->is_superadmin || ! $u->business_id, 403);
        $sep = str_contains($r->input('redirect_uri'), '?') ? '&' : '?';
        $state = $r->filled('state') ? ['state' => $r->input('state')] : [];
        if ($r->input('decision') !== 'permitir') return Inertia::location($r->input('redirect_uri') . $sep . http_build_query(['error' => 'access_denied'] + $state));
        $modo = array_key_exists($r->input('modo'), McpConexion::MODOS) ? $r->input('modo') : 'lectura';
        $code = Str::random(48);
        Cache::put('mcp_code:' . hash('sha256', $code), ['user_id' => $u->id, 'client_id' => $cli->client_id, 'redirect_uri' => $r->input('redirect_uri'), 'challenge' => $r->input('code_challenge'), 'modo' => $modo, 'nombre' => $cli->nombre], now()->addMinutes(5));
        return Inertia::location($r->input('redirect_uri') . $sep . http_build_query(['code' => $code] + $state));
    }

    private function validarPedido(Request $r): array
    {
        $cli = McpCliente::where('client_id', (string) $r->input('client_id'))->first();
        if (! $cli) return [null, 'La aplicación que pide acceso no está registrada.'];
        if (! in_array($r->input('redirect_uri'), $cli->redirect_uris, true)) return [null, 'La dirección de regreso no coincide con la registrada.'];
        if ($r->input('response_type') !== 'code') return [null, 'Tipo de respuesta no soportado.'];
        if ($r->input('code_challenge_method') !== 'S256' || strlen((string) $r->input('code_challenge')) < 43) return [null, 'Falta PKCE (S256).'];
        return [$cli, null];
    }

    public function token(Request $r)
    {
        return match ($r->input('grant_type')) {
            'authorization_code' => $this->porCodigo($r),
            'refresh_token' => $this->porRefresh($r),
            default => $this->falla('unsupported_grant_type', 'grant_type no soportado.'),
        };
    }

    private function porCodigo(Request $r)
    {
        $d = Cache::pull('mcp_code:' . hash('sha256', (string) $r->input('code')));
        if (! $d || $d['client_id'] !== $r->input('client_id') || $d['redirect_uri'] !== $r->input('redirect_uri')) return $this->falla('invalid_grant', 'El código no es válido o venció.');
        $ver = (string) $r->input('code_verifier');
        if (! hash_equals($d['challenge'], rtrim(strtr(base64_encode(hash('sha256', $ver, true)), '+/', '-_'), '='))) return $this->falla('invalid_grant', 'PKCE no coincide.');
        $u = User::find($d['user_id']); if (! $u || $u->is_superadmin) return $this->falla('invalid_grant', 'Usuario inválido.');
        $con = McpConexion::withoutGlobalScopes()->create(['business_id' => $u->business_id, 'user_id' => $u->id, 'nombre' => $d['nombre'], 'client_id' => $d['client_id'], 'modo' => $d['modo']]);
        \Illuminate\Support\Facades\Auth::setUser($u);
        AuditLog::registrar('crear', $con, "{$u->name} conectó la IA {$d['nombre']} (" . McpConexion::MODOS[$d['modo']] . ')');
        return $this->emitir($u, $con);
    }

    private function porRefresh(Request $r)
    {
        $con = McpConexion::withoutGlobalScopes()->where('refresh_hash', hash('sha256', (string) $r->input('refresh_token')))->whereNull('revocada_en')->first();
        if (! $con || ($r->filled('client_id') && $con->client_id !== $r->input('client_id'))) return $this->falla('invalid_grant', 'El refresh token no es válido.');
        $u = $con->user; if (! $u || $u->is_superadmin) return $this->falla('invalid_grant', 'Usuario inválido.');
        return $this->emitir($u, $con);
    }

    // Token de acceso Sanctum (habilidad "mcp", vence en unas horas) + refresh token rotativo. El anterior se borra.
    private function emitir(User $u, McpConexion $con)
    {
        if ($con->token_id) $u->tokens()->whereKey($con->token_id)->delete();
        $t = $u->createToken('MCP · ' . $con->nombre, ['mcp'], now()->addHours(self::HORAS_TOKEN));
        $refresh = Str::random(64);
        $con->forceFill(['token_id' => $t->accessToken->id, 'refresh_hash' => hash('sha256', $refresh)])->save();
        return response()->json(['access_token' => $t->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => self::HORAS_TOKEN * 3600, 'refresh_token' => $refresh, 'scope' => 'mcp'], 200, ['Cache-Control' => 'no-store']);
    }

    private function falla(string $code, string $desc)
    {
        return response()->json(['error' => $code, 'error_description' => $desc], 400);
    }
}
