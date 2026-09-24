<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenTrabajoItem extends Model
{
    protected $table = 'orden_trabajo_items';
    protected $fillable = ['orden_trabajo_id', 'product_id', 'tipo', 'descripcion', 'cantidad', 'precio_unit', 'total'];
    protected $casts = ['cantidad' => 'decimal:3', 'precio_unit' => 'decimal:2', 'total' => 'decimal:2'];

    public function product() { return $this->belongsTo(Product::class); }
}
