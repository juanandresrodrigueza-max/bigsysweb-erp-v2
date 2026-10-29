<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Código interno de la caja (un repuesto, un envase) que no es el código de barras de la ficha: aprendido para el negocio.
class CodigoPistola extends Model
{
    use BelongsToBusiness;

    protected $table = 'codigos_pistola';
    protected $fillable = ['business_id', 'product_id', 'codigo', 'user_id'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
