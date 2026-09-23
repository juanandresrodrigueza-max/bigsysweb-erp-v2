<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Cupón de tarjeta: nace con el cobro y se liquida cuando el emisor acredita en el banco.
class CuponTarjeta extends Model
{
    use BelongsToBusiness;

    protected $table = 'cupones_tarjeta';
    protected $fillable = ['business_id', 'cobro_id', 'cobro_medio_id', 'contact_id', 'tarjeta', 'numero', 'lote', 'cuotas', 'monto', 'fecha', 'estado', 'liquidacion_tarjeta_id'];
    protected $casts = ['monto' => 'decimal:2', 'fecha' => 'date'];

    public const TARJETAS = ['Visa', 'Mastercard', 'American Express', 'Cabal', 'Naranja', 'Maestro', 'Visa Débito', 'Mastercard Débito', 'Otra'];

    public function cobro(): BelongsTo { return $this->belongsTo(Cobro::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function liquidacion(): BelongsTo { return $this->belongsTo(LiquidacionTarjeta::class, 'liquidacion_tarjeta_id'); }
    public function scopeEnCartera(Builder $q): Builder { return $q->where('estado', 'cartera'); }
}
