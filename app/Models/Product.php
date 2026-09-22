<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'business_location_id', 'name', 'sku', 'description', 'price', 'prices', 'cost', 'iva', 'stock', 'stock_min', 'unit', 'active'];

    protected $casts = ['price' => 'decimal:2', 'cost' => 'decimal:2', 'iva' => 'decimal:2', 'prices' => 'array', 'active' => 'boolean'];

    public function precioLista(int $lista = 1): float
    {
        if ($lista <= 1) {
            return (float) $this->price;
        }
        $p = $this->prices[(string) $lista] ?? $this->prices[$lista] ?? null;
        return $p !== null && $p !== '' ? (float) $p : (float) $this->price;
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
