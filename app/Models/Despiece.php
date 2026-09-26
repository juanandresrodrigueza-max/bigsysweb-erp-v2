<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Plantilla de despiece: qué cortes salen de una materia prima (media res, cerdo, pollo) y con qué rinde esperado.
class Despiece extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'nombre', 'product_id', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function cortes(): HasMany { return $this->hasMany(DespieceCorte::class)->orderBy('orden')->orderBy('id'); }
}
