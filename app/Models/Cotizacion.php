<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cotización del dólar: la general la baja el sistema todos los días; la empresa puede fijar la suya.
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';
    protected $fillable = ['business_id', 'fecha', 'tipo', 'compra', 'venta', 'fuente'];
    protected $casts = ['fecha' => 'date', 'compra' => 'decimal:2', 'venta' => 'decimal:2'];

    public const TIPOS = ['oficial' => 'Oficial', 'blue' => 'Blue', 'mep' => 'MEP', 'tarjeta' => 'Tarjeta'];

    // Última cotización vigente para la empresa (propia primero, después la general).
    public static function actual(?int $businessId, string $tipo = 'oficial'): ?self
    {
        return self::where('tipo', $tipo)->where(fn($q) => $q->where('business_id', $businessId)->orWhereNull('business_id'))
            ->orderByRaw('business_id IS NULL')->orderByDesc('fecha')->first();
    }

    public static function valor(?int $businessId, string $tipo = 'oficial'): float
    {
        return (float) (self::actual($businessId, $tipo)?->venta ?? 0);
    }
}
