<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespieceOperacionItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['operacion_id', 'product_id', 'kg', 'rinde_real', 'rinde_esperado', 'precio_venta', 'costo_kg'];
    protected $casts = ['kg' => 'decimal:3', 'rinde_real' => 'decimal:3', 'rinde_esperado' => 'decimal:3', 'precio_venta' => 'decimal:2', 'costo_kg' => 'decimal:4'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
