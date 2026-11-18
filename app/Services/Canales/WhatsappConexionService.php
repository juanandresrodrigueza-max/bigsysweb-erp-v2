<?php

namespace App\Services\Canales;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\SistemaConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Fase 118: «Conectar WhatsApp» con el QR de Meta (Embedded Signup con coexistencia), igual que en el CRM y con la misma
// aplicación de Meta. La empresa no copia Phone ID ni tokens: escanea el QR desde la app WhatsApp Business, el número sigue
// andando en el celular y los mensajes entran al ERP. Cada número suscribe su propia URL de avisos (override_callback_uri),
// así los números conectados desde el ERP avisan al ERP y los del CRM al CRM, aunque compartan la aplicación.
class WhatsappConexionService
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    // La aplicación de Meta de BigSys (Panel → Sistema). Sin esto el botón no se puede usar y queda el modo manual.
    public static function app(): array
    {
        return ['app_id' => SistemaConfig::get('meta_app_id'), 'app_secret' => SistemaConfig::get('meta_app_secret'), 'config_id' => SistemaConfig::get('meta_config_id')];
    }

    public static function appConfigurada(): bool
    {
        $a = self::app();
        return ! empty($a['app_id']) && ! empty($a['app_secret']) && ! empty($a['config_id']);
    }

    // Lo que ve la pantalla de Configuración → Tienda y canales.
    public static function estado(Business $b): array
    {
        $ws = $b->whatsapp_settings ?? [];
        $conectado = ! empty($ws['token']) && ! empty($ws['phone_id']);
        return [
            'conectado' => $conectado,
            'modo' => $conectado ? ($ws['modo'] ?? 'manual') : null,
            'numero' => $ws['numero'] ?? null,
            'nombre' => $ws['nombre_verificado'] ?? null,
            'waba_id' => $ws['waba_id'] ?? null,
            'conectado_en' => $ws['conectado_en'] ?? null,
            'app_lista' => self::appConfigurada(),
            'app_id' => self::app()['app_id'],
            'config_id' => self::app()['config_id'],
        ];
    }

    // Termina el Embedded Signup: canjea el code por el token de la empresa, busca el número, suscribe la URL del ERP y registra.
    // Devuelve el estado nuevo o lanza una excepción con un mensaje para mostrar.
    public function conectar(Business $b, string $code, ?string $phoneId = null, ?string $wabaId = null, ?\App\Models\User $por = null): array
    {
        if (! self::appConfigurada()) throw new \RuntimeException('Falta configurar la aplicación de Meta en Panel → Sistema (App ID, secret y Configuration ID).');
        $app = self::app();

        $tok = Http::timeout(20)->get(self::GRAPH . '/oauth/access_token', ['client_id' => $app['app_id'], 'client_secret' => $app['app_secret'], 'code' => $code]);
        if (! $tok->ok() || ! $tok->json('access_token')) throw new \RuntimeException('Meta no aceptó el código de la conexión: ' . ($tok->json('error.message') ?: 'reintentá desde el botón.'));
        $token = $tok->json('access_token');

        // Si el popup no nos dijo el número (sessionInfo viejo), buscamos la WABA y el primer número que tenga.
        if (! $wabaId) {
            $deb = Http::timeout(20)->get(self::GRAPH . '/debug_token', ['input_token' => $token, 'access_token' => $app['app_id'] . '|' . $app['app_secret']]);
            foreach ((array) $deb->json('data.granular_scopes', []) as $g) if (($g['scope'] ?? '') === 'whatsapp_business_management' && ! empty($g['target_ids'][0])) { $wabaId = (string) $g['target_ids'][0]; break; }
        }
        if (! $wabaId) throw new \RuntimeException('No pudimos identificar la cuenta de WhatsApp Business. Volvé a intentar y completá todos los pasos del QR.');

        $numeros = Http::withToken($token)->timeout(20)->get(self::GRAPH . "/{$wabaId}/phone_numbers", ['fields' => 'id,display_phone_number,verified_name,code_verification_status,platform_type']);
        $lista = collect((array) $numeros->json('data', []));
        $num = $phoneId ? $lista->firstWhere('id', (string) $phoneId) : null;
        $num = $num ?: $lista->first();
        if (! $num && ! $phoneId) throw new \RuntimeException('La cuenta de WhatsApp Business no tiene ningún número. Terminá el QR en la app WhatsApp Business y probá de nuevo.');
        $phoneId = (string) ($num['id'] ?? $phoneId);

        // Fase 118.1: si el número ya está conectado en el CRM (línea oficial o por QR), no lo conectamos acá: responderían los dos.
        if ($uso = $this->numeroEnUsoEnCrm($b, $num['display_phone_number'] ?? null, $phoneId)) {
            throw new \RuntimeException('Ese número ya está conectado en el CRM' . (! empty($uso['empresa']) ? " ({$uso['empresa']})" : '') . '. Usalo desde el CRM o desconectalo allá antes de conectarlo al ERP.');
        }

        // Avisos de este número → URL propia del ERP con override_callback_uri. La URL general de la app es la del CRM y NO se toca
        // (nunca llamar a /{app-id}/subscriptions: cambiaría el webhook de toda la app y las líneas del CRM empezarían a llegar acá).
        $ws = $b->whatsapp_settings ?? [];
        $verify = $ws['verify_token'] ?? Str::random(24);
        $sub = Http::withToken($token)->timeout(20)->post(self::GRAPH . "/{$wabaId}/subscribed_apps", ['override_callback_uri' => url('/api/whatsapp/entrante/' . $b->id), 'verify_token' => $verify]);
        if (! $sub->ok() || ! $sub->json('success')) {
            throw new \RuntimeException('Meta no dejó suscribir los avisos del número al ERP: ' . ($sub->json('error.message') ?: 'reintentá en un minuto.'));
        }

        // Sin /register ni PIN, a propósito: con coexistencia el número ya lo registró la app del celular y registrarlo de nuevo lo rompe.
        // Sincronización inicial de la app del celular (contactos e historial); si falla no frena el alta.
        $sincronizado = false;
        try {
            $sync = Http::withToken($token)->timeout(20)->post(self::GRAPH . "/{$phoneId}/smb_app_data", ['messaging_product' => 'whatsapp', 'sync_type' => 'smb_app_state_sync']);
            $hist = Http::withToken($token)->timeout(20)->post(self::GRAPH . "/{$phoneId}/smb_app_data", ['messaging_product' => 'whatsapp', 'sync_type' => 'history']);
            $sincronizado = $sync->ok() || $hist->ok();
        } catch (\Throwable $e) { report($e); }

        $nuevo = array_merge($ws, [
            'modo' => 'qr', 'token' => $token, 'phone_id' => $phoneId, 'waba_id' => $wabaId, 'verify_token' => $verify,
            'numero' => $num['display_phone_number'] ?? ($ws['numero'] ?? null), 'nombre_verificado' => $num['verified_name'] ?? null,
            'coexistencia' => ($num['platform_type'] ?? null) === 'CLOUD_API' ? false : true,
            'sincronizado' => $sincronizado, 'conectado_en' => now()->toDateTimeString(),
            'auto_pedidos' => $ws['auto_pedidos'] ?? true, 'auto_responder' => $ws['auto_responder'] ?? true, 'atencion_clientes' => $ws['atencion_clientes'] ?? true, 'bigcito_equipo' => $ws['bigcito_equipo'] ?? true,
        ]);
        $b->update(['whatsapp_settings' => $nuevo]);
        AuditLog::registrar('editar', $b, 'WhatsApp conectado con el QR de Meta: ' . ($nuevo['numero'] ?? $phoneId));
        return self::estado($b->fresh());
    }

    // Saca el token y el número; si se puede, también borra la suscripción en Meta. Las preferencias (Bigcito, auto-pedidos) quedan.
    public function desconectar(Business $b, ?\App\Models\User $por = null): void
    {
        $ws = $b->whatsapp_settings ?? [];
        if (! empty($ws['token']) && ! empty($ws['waba_id'])) {
            try { Http::withToken($ws['token'])->timeout(10)->delete(self::GRAPH . "/{$ws['waba_id']}/subscribed_apps"); } catch (\Throwable $e) { report($e); }
        }
        $num = $ws['numero'] ?? ($ws['phone_id'] ?? '');
        unset($ws['token'], $ws['phone_id'], $ws['waba_id'], $ws['numero'], $ws['nombre_verificado'], $ws['conectado_en'], $ws['registrado'], $ws['sincronizado'], $ws['coexistencia'], $ws['modo'], $ws['pin']);
        $b->update(['whatsapp_settings' => $ws]);
        AuditLog::registrar('editar', $b, 'WhatsApp desconectado' . ($num ? " ({$num})" : ''));
    }

    // Fase 118.1: le pregunta al CRM si ese número ya está conectado allá (GET /api/erp/numero-en-uso). Sin CRM o si no responde, null.
    public function numeroEnUsoEnCrm(Business $b, ?string $numero, ?string $phoneId): ?array
    {
        if (! \App\Services\Integraciones\CrmService::activo($b) || \App\Services\Integraciones\CrmService::config($b)['api_key'] === '') return null;
        try {
            $r = Http::timeout(8)->withToken(\App\Services\Integraciones\CrmService::config($b)['api_key'])->acceptJson()
                ->get(\App\Services\Integraciones\CrmService::url($b, '/api/erp/numero-en-uso'), array_filter(['numero' => $numero ? preg_replace('/\D/', '', $numero) : null, 'phone_number_id' => $phoneId]));
            if ($r->ok() && $r->json('en_uso')) return ['empresa' => $r->json('empresa'), 'tipo' => $r->json('tipo')];
        } catch (\Throwable $e) { report($e); }
        return null;
    }

    // Fase 118.1: los avisos de Meta vienen firmados con el App Secret (X-Hub-Signature-256). Si la app está configurada, se exige.
    public static function firmaValida(string $cuerpo, ?string $cabecera): bool
    {
        $secret = self::app()['app_secret'];
        if (! $secret) return true; // sin app de Meta cargada (modo manual puro) no hay con qué verificar
        if (! $cabecera || ! str_starts_with($cabecera, 'sha256=')) return false;
        return hash_equals(hash_hmac('sha256', $cuerpo, $secret), substr($cabecera, 7));
    }

    // Manda un mensaje de prueba al número indicado para ver que la conexión manda de verdad.
    public function probar(Business $b, string $telefono): array
    {
        return app(WhatsappPedidosService::class)->responder($b, $telefono, "✅ Prueba de BigSysWeb: tu WhatsApp quedó conectado al ERP de {$b->name}.");
    }
}
