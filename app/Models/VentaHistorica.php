<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 101: una venta por artículo y día del sistema anterior (BigSys). Historia para reponer y para las estadísticas del primer año.
class VentaHistorica extends Model
{
    use BelongsToBusiness;

    protected $table = 'ventas_historicas';
    protected $fillable = ['business_id', 'product_id', 'fecha', 'cantidad', 'importe', 'costo', 'origen'];
    protected $casts = ['fecha' => 'date', 'cantidad' => 'decimal:3', 'importe' => 'decimal:2', 'costo' => 'decimal:2'];

    // Promedio mensual vendido por artículo en los últimos N meses: [product_id => cantidad por mes].
    public static function promedioMensual(int $businessId, int $meses = 6, ?array $productIds = null): array
    {
        $desde = today()->subMonths($meses)->startOfMonth()->toDateString();
        $q = static::withoutGlobalScopes()->where('business_id', $businessId)->where('fecha', '>=', $desde)->when($productIds !== null, fn($q) => $q->whereIn('product_id', $productIds))
            ->selectRaw('product_id, SUM(cantidad) c, MIN(fecha) d')->groupBy('product_id')->get();
        $out = [];
        // Meses calendario desde el primero con ventas hasta el último mes completo (el actual, a medias, no cuenta).
        $ultimo = today()->startOfMonth();
        foreach ($q as $r) { $m = max(1, min($meses, (int) \Carbon\Carbon::parse($r->d)->startOfMonth()->diffInMonths($ultimo))); $out[$r->product_id] = round((float) $r->c / $m, 2); }
        return $out;
    }
}
