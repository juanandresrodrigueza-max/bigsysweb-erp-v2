<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use BelongsToBusiness, SoftDeletes;

    public const TIPOS = ['producto' => 'Producto (se compra y se vende)', 'insumo' => 'Insumo (se compra, se usa en producción)', 'elaborado' => 'Elaborado (se produce y se vende)', 'servicio' => 'Servicio (sin stock)'];
    public const UNIDADES = ['un' => 'unidad', 'kg' => 'kg', 'g' => 'gramo', 'lt' => 'litro', 'ml' => 'ml', 'm' => 'metro', 'm2' => 'm²', 'm3' => 'm³', 'bolsa' => 'bolsa', 'caja' => 'caja', 'par' => 'par', 'hs' => 'hora'];

    protected $fillable = [
        'business_id', 'business_location_id', 'rubro_id', 'name', 'sku', 'tipo', 'barcode', 'marca', 'proveedor_id', 'description',
        'price', 'prices', 'cost', 'iva', 'stock', 'stock_min', 'unit', 'active', 'controla_stock', 'precio_actualizado_en', 'va_cocina', 'favorito_pos',
    ];

    protected $casts = ['price' => 'decimal:2', 'cost' => 'decimal:2', 'iva' => 'decimal:2', 'prices' => 'array', 'active' => 'boolean', 'controla_stock' => 'boolean', 'va_cocina' => 'boolean', 'favorito_pos' => 'boolean', 'stock' => 'decimal:3', 'stock_min' => 'decimal:3', 'precio_actualizado_en' => 'datetime'];

    public function precioLista(int $lista = 1): float
    {
        if ($lista <= 1) {
            return (float) $this->price;
        }
        $p = $this->prices[(string) $lista] ?? $this->prices[$lista] ?? null;
        return $p !== null && $p !== '' ? (float) $p : (float) $this->price;
    }

    public function rubro(): BelongsTo { return $this->belongsTo(Rubro::class); }
    public function proveedor(): BelongsTo { return $this->belongsTo(Contact::class, 'proveedor_id'); }
    public function stocks(): HasMany { return $this->hasMany(StockDeposito::class); }
    public function receta(): HasOne { return $this->hasOne(Recipe::class)->where('is_active', true); }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockEn(int $depositoId): float
    {
        return (float) ($this->stocks()->where('deposito_id', $depositoId)->value('cantidad') ?? 0);
    }

    public function bajoMinimo(): bool
    {
        return $this->controla_stock && (float) $this->stock <= (float) $this->stock_min;
    }
}
