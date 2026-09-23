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
        'precio_compra', 'descuento_proveedor', 'margenes', 'moneda', 'desc_cant_min', 'desc_cant_pct', 'desc_cant2_min', 'desc_cant2_pct', 'imagen', 'perecedero', 'seriado', 'en_tienda', 'descripcion_tienda',
    ];

    protected $casts = ['price' => 'decimal:2', 'cost' => 'decimal:2', 'iva' => 'decimal:2', 'prices' => 'array', 'active' => 'boolean', 'controla_stock' => 'boolean', 'perecedero' => 'boolean', 'seriado' => 'boolean', 'en_tienda' => 'boolean', 'va_cocina' => 'boolean', 'favorito_pos' => 'boolean', 'stock' => 'decimal:3', 'stock_min' => 'decimal:3', 'precio_actualizado_en' => 'datetime', 'precio_compra' => 'decimal:2', 'descuento_proveedor' => 'decimal:2', 'margenes' => 'array', 'desc_cant_min' => 'decimal:3', 'desc_cant_pct' => 'decimal:2', 'desc_cant2_min' => 'decimal:3', 'desc_cant2_pct' => 'decimal:2'];

    // Precio de la lista en pesos. Si el artículo está en dólares, se convierte con la cotización vigente.
    public function precioLista(int $lista = 1): float
    {
        $p = $lista <= 1 ? (float) $this->price : (($v = $this->prices[(string) $lista] ?? $this->prices[$lista] ?? null) !== null && $v !== '' ? (float) $v : (float) $this->price);
        return $this->moneda === 'USD' ? round($p * $this->cotizacion(), 2) : $p;
    }

    public function cotizacion(): float
    {
        static $cache = [];
        return $cache[$this->business_id] ??= (Cotizacion::valor($this->business_id) ?: 1);
    }

    // Costo en pesos (para margen, CMV y valorización).
    public function costoPesos(): float
    {
        return $this->moneda === 'USD' ? round((float) $this->cost * $this->cotizacion(), 2) : (float) $this->cost;
    }

    // Cadena: precio de compra → (– descuento proveedor) → costo → (+ margen por lista) → precios. Solo actúa si hay márgenes cargados.
    public function recalcularDesdeCosto(?float $precioCompra = null): void
    {
        if ($precioCompra !== null) {
            $this->precio_compra = $precioCompra;
        }
        if ((float) $this->precio_compra > 0) {
            $this->cost = round((float) $this->precio_compra * (1 - (float) $this->descuento_proveedor / 100), 2);
        }
        $m = collect($this->margenes ?? [])->filter(fn($v) => $v !== null && $v !== '');
        if ($m->isEmpty()) return;
        $prices = $this->prices ?? [];
        foreach ($m as $lista => $margen) {
            $precio = round((float) $this->cost * (1 + (float) $margen / 100), 2);
            if ((int) $lista <= 1) $this->price = $precio; else $prices[(string) $lista] = $precio;
        }
        $this->prices = $prices ?: null;
        $this->precio_actualizado_en = now();
    }

    // % de descuento por cantidad que corresponde a esta cantidad (0 si no aplica).
    public function descuentoPorCantidad(float $cantidad): float
    {
        // Dos escalas: la segunda (cantidad mayor) pisa a la primera.
        if ((float) $this->desc_cant2_min > 0 && $cantidad >= (float) $this->desc_cant2_min) return (float) $this->desc_cant2_pct;
        return (float) $this->desc_cant_min > 0 && $cantidad >= (float) $this->desc_cant_min ? (float) $this->desc_cant_pct : 0;
    }

    public function rubro(): BelongsTo { return $this->belongsTo(Rubro::class); }
    public function proveedor(): BelongsTo { return $this->belongsTo(Contact::class, 'proveedor_id'); }
    public function stocks(): HasMany { return $this->hasMany(StockDeposito::class); }
    public function lotes(): HasMany { return $this->hasMany(Lote::class)->where('cantidad', '>', 0)->orderBy('vencimiento'); }
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
