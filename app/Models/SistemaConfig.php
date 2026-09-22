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
    ];

    public static function get(string $clave, $default = null)
    {
        $todo = static::todo();
        return array_key_exists($clave, $todo) && $todo[$clave] !== null ? $todo[$clave] : ($default ?? (static::DEFAULTS[$clave] ?? null));
    }

    public static function set(string $clave, $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => ['v' => $valor]]);
        Cache::forget('sistema_config');
    }

    public static function todo(): array
    {
        return Cache::remember('sistema_config', 300, function () {
            $db = static::all()->mapWithKeys(fn($r) => [$r->clave => $r->valor['v'] ?? null])->all();
            return array_merge(static::DEFAULTS, $db);
        });
    }

    public static function mercadoPagoConfigurado(): bool
    {
        return (bool) static::get('mp_access_token');
    }
}
