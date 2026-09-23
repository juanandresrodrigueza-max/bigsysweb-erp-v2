<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteImpuesto extends Model
{
    public $timestamps = false;
    protected $fillable = ['comprobante_id', 'tipo', 'base', 'alicuota', 'monto'];

    public function comprobante(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Comprobante::class); }
}
