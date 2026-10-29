<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Aviso del encargado que el vendedor ve al abrir la app: a todos o a uno, con vigencia; el urgente no se puede bajar.
class AvisoVendedor extends Model
{
    use BelongsToBusiness;

    protected $table = 'avisos_vendedores';
    protected $fillable = ['business_id', 'vendedor_id', 'titulo', 'texto', 'urgente', 'desde', 'hasta', 'activo', 'user_id'];
    protected $casts = ['urgente' => 'boolean', 'activo' => 'boolean', 'desde' => 'date', 'hasta' => 'date'];

    public function vendedor(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Vendedor::class); }

    public function scopeVigentes(Builder $q, ?int $vendedorId = null): Builder
    {
        $hoy = today()->toDateString();
        return $q->where('activo', true)->where(fn($w) => $w->whereNull('desde')->orWhere('desde', '<=', $hoy))->where(fn($w) => $w->whereNull('hasta')->orWhere('hasta', '>=', $hoy))
            ->where(fn($w) => $w->whereNull('vendedor_id')->when($vendedorId, fn($x) => $x->orWhere('vendedor_id', $vendedorId)));
    }
}
