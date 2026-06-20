<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'sku', 'description', 'price', 'cost', 'stock', 'stock_min', 'unit', 'active'];

    protected $casts = ['price' => 'decimal:2', 'cost' => 'decimal:2', 'active' => 'boolean'];

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
