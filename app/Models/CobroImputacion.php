<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CobroImputacion extends Model
{
    protected $table = 'cobro_imputaciones';
    public $timestamps = false;
    protected $fillable = ['cobro_id', 'comprobante_id', 'monto', 'dif_cambio'];
    protected $casts = ['monto' => 'decimal:2'];

    public function cobro(): BelongsTo { return $this->belongsTo(Cobro::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
}
