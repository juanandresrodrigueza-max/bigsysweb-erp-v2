<?php

namespace App\Services\Integraciones;

use App\Models\Business;
use App\Models\User;
use App\Support\Cuit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Puente con el CRM de BigSys (bigsysweb-saas). Los dos sistemas se hablan por API y se pasan la identidad con un
// token firmado (HMAC-SHA256, 60 segundos, un solo uso): nunca comparten cookie ni contraseña.
class CrmService
{
    public const DEFAULT = ['activo' => false, 'url' => '', 'secreto' => '', 'api_key' => '', 'webhook_secreto' => '', 'lista_precios' => 1, 'presupuesto_como' => 'presupuesto'];
    public const VIDA_TOKEN = 60; // segundos

    // Rol del ERP → rol del CRM (role_templates). Los roles a medida del CRM no se pisan.
    public const ROLES = ['dueno' => 'admin', 'administrador' => 'admin', 'encargado' => 'supervisor', 'contador' => 'supervisor', 'vendedor' => 'operator', 'cajero' => 'operator', 'deposito' => 'viewer', 'produccion' => 'viewer', 'solo_lectura' => 'viewer'];

    public static function config(Business $b): array
    {
        return array_replace(self::DEFAULT, $b->crm_settings ?? []);
    }

    public static function activo(?Business $b): bool
    {
        if (! $b) return false;
        $c = self::config($b);
        return (bool) $c['activo'] && $c['url'] !== '' && $c['secreto'] !== '';
    }

    public static function url(Business $b, string $path = ''): string
    {
        return rtrim(self::config($b)['url'], '/') . $path;
    }

    // Token para entrar al CRM ya logueado: quién sos, de qué empresa (por CUIT) y con qué rol.
    public static function firmar(Business $b, User $user, ?string $destino = null): string
    {
        $c = self::config($b);
        $payload = ['origen' => 'erp', 'uid' => $user->id, 'email' => $user->email, 'nombre' => $user->name, 'cuit' => Cuit::limpiar($b->cuit), 'empresa' => $b->name,
            'rol' => self::ROLES[$user->rolActual()?->slug ?? ''] ?? ($user->esDueno() ? 'admin' : 'operator'), 'nonce' => Str::random(24), 'iat' => time(), 'exp' => time() + self::VIDA_TOKEN, 'a' => $destino];
        return self::codificar($payload, $c['secreto']);
    }

    public static function codificar(array $payload, string $secreto): string
    {
        $cuerpo = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $cuerpo . '.' . hash_hmac('sha256', $cuerpo, $secreto);
    }

    // Verifica un token que manda el CRM. Devuelve el payload o el motivo del rechazo.
    public static function verificar(string $token, string $secreto): array
    {
        [$cuerpo, $firma] = array_pad(explode('.', $token, 2), 2, '');
        if ($cuerpo === '' || $firma === '') return ['ok' => false, 'motivo' => 'Token incompleto.'];
        if (! hash_equals(hash_hmac('sha256', $cuerpo, $secreto), $firma)) return ['ok' => false, 'motivo' => 'La firma no coincide: el secreto compartido es distinto en los dos sistemas.'];
        $p = json_decode(base64_decode(strtr($cuerpo, '-_', '+/')), true);
        if (! is_array($p) || empty($p['nonce']) || empty($p['exp'])) return ['ok' => false, 'motivo' => 'Token inválido.'];
        if ((int) $p['exp'] < time()) return ['ok' => false, 'motivo' => 'El token venció (dura ' . self::VIDA_TOKEN . ' segundos): volvé a tocar el botón.'];
        if (! Cache::add('crm_nonce_' . $p['nonce'], 1, now()->addMinutes(5))) return ['ok' => false, 'motivo' => 'Ese token ya se usó.'];
        return ['ok' => true, 'payload' => $p];
    }

    // Empresa del ERP que corresponde al CUIT que manda el CRM.
    public static function empresaPorCuit(string $cuit): ?Business
    {
        $c = Cuit::limpiar($cuit);
        if (strlen($c) !== 11) return null;
        return Business::withoutGlobalScopes()->where('is_active', true)->get(['id', 'cuit'])->first(fn($b) => Cuit::limpiar($b->cuit) === $c)?->fresh();
    }

    // Prueba la clave de API del CRM contra /api/v1/me.
    public static function probar(Business $b): array
    {
        $c = self::config($b);
        if ($c['url'] === '') return ['ok' => false, 'detalle' => 'Cargá la URL del CRM.'];
        if ($c['api_key'] === '') return ['ok' => false, 'detalle' => 'Cargá la clave de API del CRM (Configuración → API keys en el CRM).'];
        try {
            $r = Http::timeout(8)->withToken($c['api_key'])->acceptJson()->get(self::url($b, '/api/v1/me'));
            if ($r->successful()) { $d = $r->json(); return ['ok' => true, 'detalle' => 'Conectado a ' . ($d['company']['name'] ?? $d['data']['company']['name'] ?? 'la empresa del CRM') . '.', 'datos' => $d]; }
            return ['ok' => false, 'detalle' => 'El CRM respondió ' . $r->status() . ': ' . mb_substr($r->body(), 0, 200)];
        } catch (\Throwable $e) { return ['ok' => false, 'detalle' => 'No se pudo conectar: ' . $e->getMessage()]; }
    }
}
