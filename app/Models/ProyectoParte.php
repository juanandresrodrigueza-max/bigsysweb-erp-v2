<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoParte extends Model
{
    protected $fillable = ['proyecto_id', 'user_id', 'product_id', 'empleado_id', 'fecha', 'tipo', 'descripcion', 'cantidad', 'unidad', 'costo_unit', 'total', 'stock_movement_id'];
    protected $casts = ['fecha' => 'date', 'cantidad' => 'decimal:3', 'costo_unit' => 'decimal:2', 'total' => 'decimal:2'];

    public function proyecto() { return $this->belongsTo(Proyecto::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function empleado() { return $this->belongsTo(Empleado::class); }
    public function user() { return $this->belongsTo(User::class); }
}
