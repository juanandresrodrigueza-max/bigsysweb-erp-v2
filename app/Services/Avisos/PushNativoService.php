<?php

namespace App\Services\Avisos;

use App\Models\DispositivoMovil;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Fase 93: notificaciones a la app nativa por Firebase Cloud Messaging (que en iPhone las entrega por APNs).
// La cuenta de servicio de Firebase la pega el superadmin en Sistema → App del celular; sin ella, no se manda nada y no falla nada.
class PushNativoService
{
    public function configurado(): bool
    {
        return (bool) $this->cuenta();
    }

    public function cuenta(): ?array
    {
        $raw = SistemaConfig::get('fcm_service_account');
        $c = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return is_array($c) && ! empty($c['project_id']) && ! empty($c['client_email']) && ! empty($c['private_key']) ? $c : null;
    }

    public function registrar(User $u, array $d): DispositivoMovil
    {
        // El modelo y la versión se actualizan sólo si vienen: un registro sin esos datos no borra los que ya había.
        return DispositivoMovil::updateOrCreate(['hash' => hash('sha256', $d['token'])], array_filter([
            'user_id' => $u->id, 'business_id' => $u->business_id, 'plataforma' => $d['plataforma'], 'token' => $d['token'], 'ultimo_uso_en' => now(),
            'modelo' => ! empty($d['modelo']) ? mb_substr($d['modelo'], 0, 120) : null, 'version_app' => ! empty($d['version_app']) ? mb_substr($d['version_app'], 0, 30) : null,
        ], fn($v) => $v !== null));
    }

    public function baja(User $u, ?string $token): int
    {
        return DispositivoMovil::where('user_id', $u->id)->when($token, fn($q) => $q->where('hash', hash('sha256', $token)))->delete();
    }

    // Manda a todos los celulares de la persona. Los tokens que FCM da de baja se borran solos. Devuelve cuántos llegaron.
    public function enviar(User $u, string $titulo, string $texto, string $url = '/dashboard'): int
    {
        $disp = DispositivoMovil::where('user_id', $u->id)->get();
        if ($disp->isEmpty() || ! ($c = $this->cuenta())) return 0;
        $ok = 0;
        foreach ($disp as $d) {
            $r = $this->mandar($c, $d->token, $titulo, mb_substr($texto, 0, 300), $url);
            if ($r === true) { $ok++; $d->forceFill(['ultimo_envio_en' => now()])->save(); }
            elseif ($r === 'baja') $d->delete();
        }
        return $ok;
    }

    // true: llegó · 'baja': el token ya no existe · false: otro error (se deja el dispositivo).
    private function mandar(array $c, string $token, string $titulo, string $texto, string $url): bool|string
    {
        $jwt = $this->tokenAcceso($c);
        if (! $jwt) return false;
        $msg = ['message' => ['token' => $token, 'notification' => ['title' => $titulo, 'body' => $texto], 'data' => ['url' => $url],
            'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'bigsys', 'color' => '#e4003f', 'sound' => 'default', 'click_action' => 'FCM_PLUGIN_ACTIVITY']],
            'apns' => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default', 'badge' => 1, 'mutable-content' => 1]]]]];
        try {
            $r = Http::withToken($jwt)->timeout(10)->post("https://fcm.googleapis.com/v1/projects/{$c['project_id']}/messages:send", $msg);
            if ($r->successful()) return true;
            $err = (string) ($r->json('error.details.0.errorCode') ?? $r->json('error.status') ?? '');
            return in_array($err, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true) && $r->status() !== 400 ? 'baja' : ($err === 'UNREGISTERED' ? 'baja' : false);
        } catch (\Throwable) { return false; }
    }

    // OAuth2 con la cuenta de servicio: un JWT RS256 firmado con su clave privada, canjeado por un token de una hora (cacheado 50 minutos).
    public function tokenAcceso(array $c): ?string
    {
        return Cache::remember('fcm_token_' . md5($c['client_email']), 3000, function () use ($c) {
            $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = time();
            $sin = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            $firma = '';
            if (! openssl_sign($sin, $firma, $c['private_key'], OPENSSL_ALGO_SHA256)) return null;
            try {
                $r = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $sin . '.' . $b64($firma)]);
                return $r->successful() ? ($r->json('access_token') ?: null) : null;
            } catch (\Throwable) { return null; }
        });
    }
}
