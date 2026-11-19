<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 120.5: un plan de pago que el cliente aceptó hablando con Bigcito por WhatsApp. Hasta que la empresa lo aprueba no cambia nada.
class AcuerdoPago extends Model
{
    use BelongsToBusiness;

    protected $table = 'acuerdos_pago';
    protected $fillable = ['business_id', 'contact_id', 'telefono', 'estado', 'deuda', 'cuotas', 'interes_pct', 'total', 'cuota', 'primer_vencimiento', 'comprobantes', 'charla', 'plan_pago_id', 'resuelto_por', 'resuelto_en', 'motivo'];
    protected $casts = ['deuda' => 'float', 'interes_pct' => 'float', 'total' => 'float', 'cuota' => 'float', 'primer_vencimiento' => 'date', 'comprobantes' => 'array', 'charla' => 'array', 'resuelto_en' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function plan(): BelongsTo { return $this->belongsTo(PlanPago::class, 'plan_pago_id'); }
}
