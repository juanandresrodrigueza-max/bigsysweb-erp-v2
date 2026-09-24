<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoImputacion extends Model
{
    protected $table = 'pago_imputaciones';
    public $timestamps = false;
    protected $fillable = ['pago_id', 'comprobante_id', 'monto', 'dif_cambio'];
    protected $casts = ['monto' => 'decimal:2'];

    public function pago(): BelongsTo { return $this->belongsTo(Pago::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
}
