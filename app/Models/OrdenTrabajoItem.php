<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenTrabajoItem extends Model
{
    protected $table = 'orden_trabajo_items';
    protected $fillable = ['orden_trabajo_id', 'product_id', 'tipo', 'descripcion', 'cantidad', 'precio_unit', 'total', 'aprobado', 'pendiente_proxima', 'tecnicos', 'viene_de_item_id'];
    protected $casts = ['cantidad' => 'decimal:3', 'precio_unit' => 'decimal:2', 'total' => 'decimal:2', 'aprobado' => 'boolean', 'pendiente_proxima' => 'boolean', 'tecnicos' => 'array'];

    public function product() { return $this->belongsTo(Product::class); }
}
