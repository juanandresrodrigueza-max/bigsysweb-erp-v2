<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenCompraItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['orden_compra_id', 'product_id', 'descripcion', 'cantidad', 'precio_unit', 'recibido', 'notas', 'orden'];
    protected $casts = ['cantidad' => 'decimal:3', 'precio_unit' => 'decimal:4', 'recibido' => 'decimal:3'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function orden(): BelongsTo { return $this->belongsTo(OrdenCompra::class, 'orden_compra_id'); }
    public function pendiente(): float { return max(0, round((float) $this->cantidad - (float) $this->recibido, 3)); }
}
