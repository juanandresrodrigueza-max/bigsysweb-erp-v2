<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprobanteItem extends Model
{
    protected $fillable = ['comprobante_id', 'product_id', 'descripcion', 'cantidad', 'unidad', 'precio_unit', 'descuento', 'alicuota_iva', 'neto', 'iva', 'total', 'orden'];

    protected $casts = ['cantidad' => 'decimal:3', 'precio_unit' => 'decimal:4', 'descuento' => 'decimal:2', 'alicuota_iva' => 'decimal:2', 'neto' => 'decimal:2', 'iva' => 'decimal:2', 'total' => 'decimal:2'];

    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    // Precio unitario se carga neto de IVA; el total del ítem incluye IVA.
    public static function calcular(float $cantidad, float $precioUnit, float $descuentoPct, float $alicuota): array
    {
        $neto = round($cantidad * $precioUnit * (1 - $descuentoPct / 100), 2);
        $iva  = round($neto * $alicuota / 100, 2);
        return ['neto' => $neto, 'iva' => $iva, 'total' => round($neto + $iva, 2)];
    }
}
