<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Descuento especial de una lista de precios para un artículo o un rubro, desde una cantidad mínima.
class DescuentoLista extends Model
{
    use BelongsToBusiness;

    protected $table = 'descuentos_lista';
    protected $fillable = ['business_id', 'lista', 'product_id', 'rubro_id', 'cantidad_minima', 'precio', 'descuento', 'vigente_desde', 'vigente_hasta', 'user_id'];
    protected $casts = ['cantidad_minima' => 'decimal:3', 'precio' => 'decimal:2', 'descuento' => 'decimal:2', 'vigente_desde' => 'date', 'vigente_hasta' => 'date'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function rubro(): BelongsTo { return $this->belongsTo(Rubro::class); }

    public function scopeVigentes(Builder $q, ?string $fecha = null): Builder
    {
        $f = $fecha ?: today()->toDateString();
        return $q->where(fn($w) => $w->whereNull('vigente_desde')->orWhere('vigente_desde', '<=', $f))->where(fn($w) => $w->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $f));
    }
}
