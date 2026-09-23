<?php
namespace App\Models;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Fórmula / receta: qué insumos y en qué cantidad hacen falta para producir yield_quantity del producto.
class Recipe extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id', 'product_id', 'name', 'yield_quantity', 'yield_unit', 'costo_calculado', 'tiempo_minutos',
        'instructions', 'is_active',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:4',
        'costo_calculado' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function items() { return $this->hasMany(RecipeItem::class); }
    public function ordenes() { return $this->hasMany(ProductionOrder::class); }

    public function costoUnitario(): float
    {
        return (float) $this->yield_quantity > 0 ? round((float) $this->costo_calculado / (float) $this->yield_quantity, 2) : 0;
    }
}
