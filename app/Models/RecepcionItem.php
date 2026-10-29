<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecepcionItem extends Model
{
    protected $fillable = ['recepcion_id', 'comprobante_item_id', 'product_id', 'descripcion', 'esperado', 'contado', 'resolucion', 'lote', 'vencimiento'];
    protected $casts = ['esperado' => 'decimal:3', 'contado' => 'decimal:3', 'vencimiento' => 'date'];

    public function recepcion(): BelongsTo { return $this->belongsTo(Recepcion::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function diferencia(): float { return round((float) $this->contado - (float) $this->esperado, 3); }
}
