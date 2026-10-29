<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Una lista armada en el simulador del Consultor y mandada por WhatsApp o impresa. No es un comprobante (y no es la cotización del dólar).
class CotizacionConsultor extends Model
{
    use BelongsToBusiness;

    protected $table = 'cotizaciones_consultor';
    protected $fillable = ['business_id', 'user_id', 'contact_id', 'token', 'cliente', 'telefono', 'lista', 'items', 'total', 'expira_en'];
    protected $casts = ['items' => 'array', 'total' => 'decimal:2', 'expira_en' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function vigente(): bool { return ! $this->expira_en || $this->expira_en->isFuture(); }
}
