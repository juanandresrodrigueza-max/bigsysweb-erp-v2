<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Hoja de reparto: un repartidor, una fecha, varios comprobantes a entregar.
class OrdenEntrega extends Model
{
    use BelongsToBusiness;

    protected $table = 'ordenes_entrega';
    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'numero', 'fecha', 'repartidor', 'vehiculo', 'estado', 'notas', 'entregada_en'];
    protected $casts = ['fecha' => 'date', 'entregada_en' => 'datetime'];

    public const ESTADOS = ['pendiente' => 'Pendiente', 'en_curso' => 'En reparto', 'entregada' => 'Entregada', 'cancelada' => 'Cancelada'];

    public function items(): HasMany { return $this->hasMany(OrdenEntregaItem::class)->orderBy('orden'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function numeroFormateado(): string { return sprintf('HR-%06d', $this->numero); }
}
