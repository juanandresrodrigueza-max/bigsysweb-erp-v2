<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPagoCuota extends Model
{
    public $timestamps = false;
    protected $fillable = ['plan_pago_id', 'numero', 'vencimiento', 'monto', 'pagado', 'estado'];
    protected $casts = ['vencimiento' => 'date', 'monto' => 'decimal:2', 'pagado' => 'decimal:2'];

    public function plan(): BelongsTo { return $this->belongsTo(PlanPago::class, 'plan_pago_id'); }
}
