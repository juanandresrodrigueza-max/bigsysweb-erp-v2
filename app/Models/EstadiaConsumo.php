<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadiaConsumo extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    protected $table = 'estadia_consumos';
    protected $fillable = ['estadia_id', 'product_id', 'user_id', 'fecha', 'descripcion', 'cantidad', 'precio_unit', 'total'];
    protected $casts = ['fecha' => 'date', 'cantidad' => 'decimal:3', 'precio_unit' => 'decimal:2', 'total' => 'decimal:2'];

    public function product() { return $this->belongsTo(Product::class); }
}
