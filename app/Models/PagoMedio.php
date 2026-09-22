<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoMedio extends Model
{
    public $timestamps = false;
    protected $fillable = ['pago_id', 'medio', 'monto', 'cuenta_fondos_id', 'cheque_id', 'referencia', 'datos'];
    protected $casts = ['monto' => 'decimal:2', 'datos' => 'array'];

    public function cheque(): BelongsTo { return $this->belongsTo(Cheque::class); }
    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
}
