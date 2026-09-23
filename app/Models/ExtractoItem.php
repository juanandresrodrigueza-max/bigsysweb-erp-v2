<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Un renglón del extracto bancario: se concilia contra un movimiento de fondos.
class ExtractoItem extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['extracto_id', 'business_id', 'cuenta_fondos_id', 'fecha', 'descripcion', 'referencia', 'monto', 'saldo', 'estado', 'match', 'movimiento_fondos_id'];
    protected $casts = ['fecha' => 'date', 'monto' => 'decimal:2', 'saldo' => 'decimal:2'];

    public function extracto(): BelongsTo { return $this->belongsTo(ExtractoBancario::class, 'extracto_id'); }
    public function movimiento(): BelongsTo { return $this->belongsTo(MovimientoFondos::class, 'movimiento_fondos_id'); }
}
