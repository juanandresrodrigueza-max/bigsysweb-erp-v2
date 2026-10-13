<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Servidor;
use App\Models\McpConexion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

// Fase 28.1: endpoint MCP (transporte HTTP "streamable", respuestas JSON). Autenticación: Bearer emitido por OAuth o token personal.
class McpController extends Controller
{
    public function handle(Request $request, Servidor $servidor): JsonResponse|\Illuminate\Http\Response
    {
        [$u, $con] = $this->autenticar($request);
        if (! $u) return $this->noAutorizado();

        $cuerpo = json_decode($request->getContent(), true);
        if (! is_array($cuerpo)) return response()->json($servidor->error(null, -32700, 'JSON inválido.'), 400);

        $lote = array_is_list($cuerpo) && $cuerpo !== [];
        $resp = array_values(array_filter(array_map(fn($m) => is_array($m) ? $servidor->atender($m, $u, $con) : $servidor->error(null, -32600, 'Pedido inválido.'), $lote ? $cuerpo : [$cuerpo])));
        if (! $resp) return response('', 202);
        return response()->json($lote ? $resp : $resp[0], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function get(): \Illuminate\Http\Response
    {
        return response('Este servidor MCP no ofrece flujo SSE: usá POST.', 405, ['Allow' => 'POST']);
    }

    // Token Sanctum con habilidad "mcp" ligado a una conexión vigente. Recién ahí se fija el usuario: el aislamiento por empresa depende de esto.
    private function autenticar(Request $r): array
    {
        $plano = $r->bearerToken(); if (! $plano) return [null, null];
        $t = PersonalAccessToken::findToken($plano);
        if (! $t || ! $t->can('mcp') || ($t->expires_at && $t->expires_at->isPast())) return [null, null];
        $u = $t->tokenable;
        if (! $u instanceof User || $u->is_superadmin || ! $u->business_id || ! ($u->is_active ?? true)) return [null, null];
        $con = McpConexion::withoutGlobalScopes()->where('token_id', $t->id)->where('user_id', $u->id)->whereNull('revocada_en')->first();
        if (! $con || $con->business_id !== $u->business_id) return [null, null];

        Auth::setUser($u->withAccessToken($t));
        $con->forceFill(['ultimo_uso' => now(), 'usos' => $con->usos + 1])->saveQuietly();
        $t->forceFill(['last_used_at' => now()])->saveQuietly();
        return [$u, $con];
    }

    private function noAutorizado(): JsonResponse
    {
        return response()->json(['error' => 'invalid_token', 'error_description' => 'Falta un token válido. Conectá la IA desde Configuración → Conexiones IA.'], 401,
            ['WWW-Authenticate' => 'Bearer resource_metadata="' . url('/.well-known/oauth-protected-resource') . '"']);
    }
}
