<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

// Parámetros globales de BigSysWeb (días de prueba, gracia, credenciales de cobro, avisos).
class SistemaConfig extends Model
{
    protected $table = 'sistema_config';
    protected $primaryKey = 'clave';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['clave', 'valor'];
    protected $casts = ['valor' => 'array'];

    public const DEFAULTS = [
        'dias_prueba'        => 15,
        'dias_gracia'        => 7,
        'aviso_dias'         => [7, 3, 1],
        // Fase 106: altas con aprobación. Horas de acceso provisional, la clave (hash bcrypt) y a quién avisar.
        'horas_provisional'  => 72,
        'clave_aprobacion'   => null,
        'clave_aprobacion_duenos' => [], // quiénes pueden cambiarla y designar a otros (ids de superadmins); vacío + sin clave = el primero que la carga
        'aviso_registros_emails' => null,
        'aviso_registros_whatsapp' => null,
        'mp_access_token'    => null,
        'mp_public_key'      => null,
        'transferencia_cbu'  => null,
        'transferencia_alias'=> 'bigsys.erp',
        'transferencia_titular' => 'BigSys S.R.L.',
        'mensaje_global'     => null,
        'soporte_whatsapp'   => '+54 9 351 555-0000',
        'soporte_email'      => 'soporte@bigsys.com.ar',
        'mantenimiento'      => ['activo' => false, 'mensaje' => null, 'hasta' => null],
        // IA y correo: si están cargados acá pisan al .env (ver AppServiceProvider::aplicarConfigDelPanel).
        'ia_api_key'         => null,
        'ia_modelo'          => null,
        'mail_host'          => null,
        'mail_port'          => 587,
        'mail_username'      => null,
        'mail_password'      => null,
        'mail_encryption'    => 'tls',
        'fcm_service_account' => null, // Fase 93: cuenta de servicio de Firebase para los avisos de la app nativa
        'app_android_url'    => null,
        'app_ios_url'        => null,
        'android_sha256'     => null, // huellas SHA-256 de la firma de la app (deep links)
        'apple_team_id'      => null,
        'mail_from_address'  => null,
        'mail_from_name'     => 'BigSysWeb',
        // Aplicaciones de la plataforma para conectar tiendas con un botón (una sola vez para todos los clientes).
        'mp_app_id'          => null,
        'mp_app_secret'      => null,
        'ml_app_id'          => null,
        'ml_app_secret'      => null,
        'tn_app_id'          => null,
        'tn_app_secret'      => null,
        'google_client_id'   => null, // Fase 96: entrar con Google (OAuth)
        'voz_proveedor'      => 'openai', // Fase 119.2: voz de Bigcito para todas las empresas (escuchar y hablar)
        'voz_api_key'        => null,
        'voz_nombre'         => null,
        'voz_vivo'           => false, // Fase 120.4: conversación en vivo con Bigcito (OpenAI Realtime)
        'voz_vivo_modelo'    => 'gpt-realtime',
        'voz_vivo_minutos'   => 5,     // tope por llamada
        'voz_vivo_diarias'   => 30,    // llamadas por usuario por día
        'meta_app_id'        => null, // Fase 118: app de Meta (la misma del CRM) para «Conectar WhatsApp» con el QR
        'meta_app_secret'    => null,
        'meta_config_id'     => null, // Configuration ID del Embedded Signup
        'google_client_secret' => null,
        // Fase 120.3: la casilla adonde llegan las facturas de compra por mail (IMAP).
        'casilla_host'       => null,
        'casilla_puerto'     => 993,
        'casilla_usuario'    => null,
        'casilla_clave'      => null,
        'casilla_direccion'  => null, // la dirección pública si no es igual al usuario
        // Fase 121: certificado de BigSys para facturar en nombre de las empresas (delegación, como el CRM).
        'arca_cert'          => null,
        'arca_key'           => null,
        'arca_produccion'    => false,
        'arca_razon'         => null,
        'arca_pedido'        => null, // pedido de certificado (CSR) generado acá, esperando el .crt de ARCA
        'arca_pedido_key'    => null,
        'arca_pedido_alias'  => null,
    ];

    // Claves que se guardan cifradas.
    public const SECRETOS = ['mp_access_token', 'ia_api_key', 'mail_password', 'sentry_dsn', 'salud_token', 'ml_app_secret', 'tn_app_secret', 'mp_app_secret', 'vapid', 'fcm_service_account', 'google_client_secret', 'clave_aprobacion', 'meta_app_secret', 'voz_api_key', 'casilla_clave', 'arca_key', 'arca_pedido_key'];

    public static function get(string $clave, $default = null)
    {
        $todo = static::todo();
        return array_key_exists($clave, $todo) && $todo[$clave] !== null ? $todo[$clave] : ($default ?? (static::DEFAULTS[$clave] ?? null));
    }

    public static function set(string $clave, $valor): void
    {
        if (in_array($clave, self::SECRETOS, true) && $valor) $valor = ['enc' => \Illuminate\Support\Facades\Crypt::encryptString((string) $valor)];
        static::updateOrCreate(['clave' => $clave], ['valor' => ['v' => $valor]]);
        Cache::forget('sistema_config'); static::$memo = null;
    }

    private static ?array $memo = null; // Fase 94.2: dentro de un mismo pedido se lee una sola vez

    // Para las pruebas: cada prueba arranca con la base limpia, y lo leído en la anterior no tiene que sobrevivir.
    public static function olvidar(): void { static::$memo = null; }

    public static function todo(): array
    {
        return static::$memo ??= Cache::remember('sistema_config', 300, function () {
            $db = static::all()->mapWithKeys(function ($r) { $v = $r->valor['v'] ?? null; if (is_array($v) && isset($v['enc'])) { try { $v = \Illuminate\Support\Facades\Crypt::decryptString($v['enc']); } catch (\Throwable $e) { $v = null; } } return [$r->clave => $v]; })->all();
            return array_merge(static::DEFAULTS, $db);
        });
    }

    // Pisa la configuración del .env con lo cargado en el panel (IA y correo). Se llama al arrancar; si la tabla no existe todavía, no hace nada.
    public static function aplicar(): void
    {
        try { $c = static::todo(); } catch (\Throwable $e) { return; }
        if (! empty($c['ia_api_key'])) config(['services.anthropic.api_key' => $c['ia_api_key']]);
        if (! empty($c['ia_modelo'])) config(['services.anthropic.model' => $c['ia_modelo']]);
        if (! empty($c['mail_host'])) {
            config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => $c['mail_host'], 'mail.mailers.smtp.port' => (int) ($c['mail_port'] ?: 587), 'mail.mailers.smtp.username' => $c['mail_username'], 'mail.mailers.smtp.password' => $c['mail_password'], 'mail.mailers.smtp.scheme' => ($c['mail_encryption'] ?? 'tls') === 'ssl' ? 'smtps' : null, 'mail.mailers.smtp.encryption' => $c['mail_encryption'] ?: null]);
            if (! empty($c['mail_from_address'])) config(['mail.from.address' => $c['mail_from_address'], 'mail.from.name' => $c['mail_from_name'] ?: 'BigSysWeb']);
        }
    }

    public static function mercadoPagoConfigurado(): bool
    {
        return (bool) static::get('mp_access_token');
    }
}
