<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Reserva / estadía de un huésped en una habitación: reserva → check-in → consumos → check-out con factura.
class Estadia extends Model
{
    use BelongsToBusiness;

    public const ESTADOS = ['reservada' => 'Reservada', 'checkin' => 'Alojado', 'checkout' => 'Finalizada', 'cancelada' => 'Cancelada', 'no_show' => 'No vino'];

    protected $fillable = ['business_id', 'business_location_id', 'habitacion_id', 'contact_id', 'nombre', 'telefono', 'email', 'documento', 'personas', 'desde', 'hasta', 'estado', 'tarifa_noche', 'senia', 'origen', 'comprobante_id', 'checkin_en', 'checkout_en', 'token', 'notas'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'tarifa_noche' => 'decimal:2', 'senia' => 'decimal:2', 'checkin_en' => 'datetime', 'checkout_en' => 'datetime'];

    public function habitacion() { return $this->belongsTo(Habitacion::class); }
    public function contact() { return $this->belongsTo(Contact::class); }
    public function consumos() { return $this->hasMany(EstadiaConsumo::class)->orderBy('fecha')->orderBy('id'); }
    public function comprobante() { return $this->belongsTo(Comprobante::class); }

    public function noches(): int { return max(1, (int) $this->desde->diffInDays($this->hasta)); }
    public function totalAlojamiento(): float { return round($this->noches() * (float) $this->tarifa_noche, 2); }
    public function totalConsumos(): float { return round((float) $this->consumos()->sum('total'), 2); }
    public function total(): float { return round($this->totalAlojamiento() + $this->totalConsumos(), 2); }
    public function saldo(): float { return round($this->total() - (float) $this->senia, 2); }
}
