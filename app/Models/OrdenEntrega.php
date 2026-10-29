<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Hoja de reparto: un repartidor, una fecha, varios comprobantes a entregar.
class OrdenEntrega extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'ordenes_entrega';
    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'numero', 'fecha', 'repartidor', 'vehiculo', 'estado', 'notas', 'entregada_en', 'rendida_en', 'rendida_por', 'cuenta_fondos_id', 'rendicion', 'token_chofer', 'salida_en', 'ultima_lat', 'ultima_lng', 'ultima_pos_en', 'origen_lat', 'origen_lng'];
    protected $casts = ['fecha' => 'date', 'entregada_en' => 'datetime', 'rendida_en' => 'datetime', 'rendicion' => 'array', 'salida_en' => 'datetime', 'ultima_pos_en' => 'datetime', 'ultima_lat' => 'float', 'ultima_lng' => 'float', 'origen_lat' => 'float', 'origen_lng' => 'float'];

    public const ESTADOS = ['pendiente' => 'Pendiente', 'en_curso' => 'En reparto', 'entregada' => 'Entregada', 'cancelada' => 'Cancelada'];

    public function items(): HasMany { return $this->hasMany(OrdenEntregaItem::class)->orderBy('orden'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function cobros(): HasMany { return $this->hasMany(OrdenEntregaCobro::class)->orderBy('id'); }
    public function cuenta(): BelongsTo { return $this->belongsTo(CuentaFondos::class, 'cuenta_fondos_id'); }
    public function numeroFormateado(): string { return sprintf('HR-%06d', $this->numero); }
}
