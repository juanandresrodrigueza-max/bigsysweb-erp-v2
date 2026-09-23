<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cobro extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const MEDIOS = [
        'efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'mercadopago' => 'MercadoPago',
        'billetera' => 'Billetera virtual', 'tarjeta' => 'Tarjeta', 'retencion' => 'Retención',
    ];

    protected $fillable = ['business_id', 'business_location_id', 'contact_id', 'user_id', 'vendedor_id', 'numero', 'fecha', 'total', 'descuento', 'interes', 'a_cuenta', 'estado', 'notas', 'cotizacion'];
    protected $casts = ['fecha' => 'date', 'total' => 'decimal:2', 'a_cuenta' => 'decimal:2', 'descuento' => 'decimal:2', 'interes' => 'decimal:2'];

    public function vendedor(): BelongsTo { return $this->belongsTo(Vendedor::class); }

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function medios(): HasMany { return $this->hasMany(CobroMedio::class); }
    public function imputaciones(): HasMany { return $this->hasMany(CobroImputacion::class); }

    public function numeroFormateado(): string { return sprintf('REC %08d', $this->numero); }
}
