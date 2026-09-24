<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Condición comercial de un cliente: precio fijo o descuento para un artículo, o descuento para un rubro.
class PrecioPactado extends Model
{
    use BelongsToBusiness;

    protected $table = 'precios_pactados';
    protected $fillable = ['business_id', 'contact_id', 'product_id', 'rubro_id', 'precio', 'descuento', 'origen', 'vigente_hasta', 'user_id'];
    protected $casts = ['precio' => 'decimal:2', 'descuento' => 'decimal:2', 'vigente_hasta' => 'date'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function rubro(): BelongsTo { return $this->belongsTo(Rubro::class); }

    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where(fn($w) => $w->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', today()->toDateString()));
    }
}
