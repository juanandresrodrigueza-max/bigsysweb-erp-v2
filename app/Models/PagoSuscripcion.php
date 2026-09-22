<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Un cobro de suscripción: lo que una empresa le paga a BigSys por un período del plan.
class PagoSuscripcion extends Model
{
    protected $table = 'pagos_suscripcion';

    public const MEDIOS = ['mercadopago' => 'MercadoPago', 'transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cortesia' => 'Cortesía / bonificado'];
    public const ESTADOS = ['pendiente' => 'Pendiente', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'anulado' => 'Anulado'];

    protected $fillable = [
        'business_id', 'subscription_id', 'plan_id', 'fecha', 'ciclo', 'periodo_desde', 'periodo_hasta', 'monto', 'medio',
        'estado', 'external_id', 'preference_id', 'referencia', 'notas', 'user_id', 'aprobado_en',
    ];

    protected $casts = ['fecha' => 'date', 'periodo_desde' => 'date', 'periodo_hasta' => 'date', 'monto' => 'decimal:2', 'aprobado_en' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
