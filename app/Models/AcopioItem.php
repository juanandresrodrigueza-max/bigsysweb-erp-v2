<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcopioItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['acopio_id', 'product_id', 'descripcion', 'cantidad_facturada', 'cantidad_retirada', 'precio_congelado'];
    protected $casts = ['cantidad_facturada' => 'decimal:3', 'cantidad_retirada' => 'decimal:3', 'precio_congelado' => 'decimal:4'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function pendiente(): float { return round((float) $this->cantidad_facturada - (float) $this->cantidad_retirada, 3); }
}
