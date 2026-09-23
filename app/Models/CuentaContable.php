<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Cuenta del plan contable. Las "claves" son los roles que usa el sistema para armar asientos solos.
class CuentaContable extends Model
{
    use BelongsToBusiness;

    protected $table = 'cuentas_contables';

    public const TIPOS = ['activo' => 'Activo', 'pasivo' => 'Pasivo', 'patrimonio' => 'Patrimonio neto', 'ingreso' => 'Ingresos', 'egreso' => 'Egresos'];

    protected $fillable = ['business_id', 'parent_id', 'codigo', 'nombre', 'tipo', 'clave', 'imputable', 'activa'];
    protected $casts = ['imputable' => 'boolean', 'activa' => 'boolean'];

    public function parent(): BelongsTo { return $this->belongsTo(CuentaContable::class, 'parent_id'); }
    public function hijas(): HasMany { return $this->hasMany(CuentaContable::class, 'parent_id')->orderBy('codigo'); }
    public function lineas(): HasMany { return $this->hasMany(AsientoLinea::class, 'cuenta_id'); }

    // Saldo deudor positivo para activo/egreso, acreedor positivo para pasivo/patrimonio/ingreso.
    public function naturalezaDeudora(): bool
    {
        return in_array($this->tipo, ['activo', 'egreso'], true);
    }

    public static function porClave(string $clave): ?self
    {
        return static::where('clave', $clave)->where('activa', true)->first();
    }
}
