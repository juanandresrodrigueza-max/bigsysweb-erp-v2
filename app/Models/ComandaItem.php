<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComandaItem extends Model
{
    public const ESTADOS = ['pedido' => 'Pedido', 'cocina' => 'En cocina', 'listo' => 'Listo', 'entregado' => 'Entregado', 'anulado' => 'Anulado'];

    protected $fillable = ['comanda_id', 'product_id', 'descripcion', 'cantidad', 'precio_unit', 'alicuota_iva', 'notas', 'estado', 'va_cocina', 'ronda', 'enviado_en', 'listo_en'];
    protected $casts = ['cantidad' => 'decimal:3', 'precio_unit' => 'decimal:2', 'alicuota_iva' => 'decimal:2', 'va_cocina' => 'boolean', 'enviado_en' => 'datetime', 'listo_en' => 'datetime'];

    public function comanda(): BelongsTo { return $this->belongsTo(Comanda::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
