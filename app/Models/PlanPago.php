<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Refinanciación: deuda vencida + interés, dividida en cuotas con vencimiento.
class PlanPago extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'planes_pago';
    protected $fillable = ['business_id', 'contact_id', 'user_id', 'numero', 'fecha', 'deuda', 'interes_pct', 'interes', 'total', 'cuotas_n', 'nd_comprobante_id', 'estado', 'notas'];
    protected $casts = ['fecha' => 'date', 'deuda' => 'decimal:2', 'interes_pct' => 'decimal:2', 'interes' => 'decimal:2', 'total' => 'decimal:2'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function cuotas(): HasMany { return $this->hasMany(PlanPagoCuota::class)->orderBy('numero'); }
    public function notaDebito(): BelongsTo { return $this->belongsTo(Comprobante::class, 'nd_comprobante_id'); }
    public function numeroFormateado(): string { return sprintf('PP-%05d', $this->numero); }
}
