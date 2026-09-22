<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CuentaFondos extends Model
{
    use BelongsToBusiness;

    public const TIPOS = ['caja' => 'Caja', 'banco' => 'Banco', 'billetera' => 'Billetera virtual', 'tarjeta' => 'Tarjeta / cupones'];

    protected $table = 'cuentas_fondos';
    protected $fillable = ['business_id', 'business_location_id', 'tipo', 'nombre', 'banco', 'cbu', 'alias', 'moneda', 'saldo', 'saldo_minimo', 'activa', 'es_default'];
    protected $casts = ['saldo' => 'decimal:2', 'saldo_minimo' => 'decimal:2', 'activa' => 'boolean', 'es_default' => 'boolean'];

    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
    public function movimientos(): HasMany { return $this->hasMany(MovimientoFondos::class, 'cuenta_fondos_id'); }
    public function turnoAbierto(): HasOne { return $this->hasOne(TurnoCaja::class, 'cuenta_fondos_id')->whereNull('cierre')->latestOfMany('apertura'); }

    public function recalcularSaldo(): float
    {
        $s = (float) $this->movimientos()->selectRaw('COALESCE(SUM(ingreso - egreso),0) as s')->value('s');
        $this->update(['saldo' => round($s, 2)]);
        return round($s, 2);
    }
}
