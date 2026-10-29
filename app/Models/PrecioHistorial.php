<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Cada cambio de precio (lista 1) o de costo de un artículo: para ver cómo venía el margen y quién lo movió.
class PrecioHistorial extends Model
{
    use BelongsToBusiness;

    protected $table = 'precio_historial';
    protected $fillable = ['business_id', 'product_id', 'user_id', 'fecha', 'precio', 'costo', 'precio_antes', 'costo_antes'];
    protected $casts = ['fecha' => 'date', 'precio' => 'decimal:2', 'costo' => 'decimal:2', 'precio_antes' => 'decimal:2', 'costo_antes' => 'decimal:2'];

    // Margen sobre el costo, como la ficha: (precio − costo) / costo.
    public static function margen(float $precio, float $costo): ?float { return $costo > 0 ? round(($precio - $costo) / $costo * 100, 2) : null; }
}
