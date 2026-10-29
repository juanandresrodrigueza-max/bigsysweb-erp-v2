<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Lo que el vendedor cobró en la calle, cerrado al terminar el turno y separado por medio, hasta que el encargado recibe la plata.
class RendicionCalle extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $table = 'rendiciones_calle';
    protected $fillable = ['business_id', 'numero', 'vendedor_id', 'user_id', 'efectivo', 'transferencias', 'cheques', 'recibos', 'estado', 'efectivo_contado', 'diferencia', 'caja_id', 'banco_id', 'recibida_por', 'recibida_at', 'notas', 'created_at'];
    protected $casts = ['efectivo' => 'float', 'transferencias' => 'float', 'cheques' => 'float', 'efectivo_contado' => 'float', 'diferencia' => 'float', 'recibida_at' => 'datetime', 'created_at' => 'datetime'];

    public function vendedor(): BelongsTo { return $this->belongsTo(Vendedor::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function recibidaPor(): BelongsTo { return $this->belongsTo(User::class, 'recibida_por'); }
    public function cobros(): HasMany { return $this->hasMany(Cobro::class, 'rendicion_id'); }
    public function numeroFormateado(): string { return sprintf('RC %06d', $this->numero); }
}
