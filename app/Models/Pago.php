<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pago extends Model
{
    use BelongsToBusiness;

    public const MEDIOS = [
        'efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'cheque_propio' => 'Cheque propio', 'cheque_tercero' => 'Cheque de tercero (endoso)',
        'billetera' => 'Billetera virtual', 'tarjeta' => 'Tarjeta', 'retencion' => 'Retención',
    ];

    protected $fillable = ['business_id', 'business_location_id', 'contact_id', 'user_id', 'numero', 'fecha', 'total', 'a_cuenta', 'estado', 'notas'];
    protected $casts = ['fecha' => 'date', 'total' => 'decimal:2', 'a_cuenta' => 'decimal:2'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function medios(): HasMany { return $this->hasMany(PagoMedio::class); }
    public function imputaciones(): HasMany { return $this->hasMany(PagoImputacion::class); }
    public function retenciones(): HasMany { return $this->hasMany(Retencion::class); }

    public function numeroFormateado(): string { return sprintf('OP %08d', $this->numero); }
}
