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
        'dias_prueba'        => 14,
        'dias_gracia'        => 7,
        'aviso_dias'         => [7, 3, 1],
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
        'mail_from_address'  => null,
        'mail_from_name'     => 'BigSysWeb',
    ];

    // Claves que se guardan cifradas.
    public const SECRETOS = ['mp_access_token', 'ia_api_key', 'mail_password', 'sentry_dsn', 'salud_token'];

    public static function get(string $clave, $default = null)
    {
        $todo = static::todo();
        return array_key_exists($clave, $todo) && $todo[$clave] !== null ? $todo[$clave] : ($default ?? (static::DEFAULTS[$clave] ?? null));
    }

    public static function set(string $clave, $valor): void
    {
        if (in_array($clave, self::SECRETOS, true) && $valor) $valor = ['enc' => \Illuminate\Support\Facades\Crypt::encryptString((string) $valor)];
        static::updateOrCreate(['clave' => $clave], ['valor' => ['v' => $valor]]);
        Cache::forget('sistema_config');
    }

    public static function todo(): array
    {
        return Cache::remember('sistema_config', 300, function () {
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
