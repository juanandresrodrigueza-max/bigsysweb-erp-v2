<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Acopio extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'contact_id', 'comprobante_id', 'fecha', 'fecha_limite', 'estado', 'observaciones'];
    protected $casts = ['fecha' => 'date', 'fecha_limite' => 'date'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function items(): HasMany { return $this->hasMany(AcopioItem::class); }
    public function retiros(): HasMany { return $this->hasMany(AcopioRetiro::class)->latest('fecha'); }

    public function actualizarEstado(): void
    {
        $items = $this->items()->get();
        $pendiente = $items->sum(fn($i) => (float) $i->cantidad_facturada - (float) $i->cantidad_retirada);
        $retirado  = $items->sum(fn($i) => (float) $i->cantidad_retirada);
        $estado = $pendiente <= 0.0005 ? 'cerrado' : ($retirado > 0 ? 'parcial' : 'abierto');
        if ($estado !== 'cerrado' && $this->fecha_limite && $this->fecha_limite->isPast()) {
            $estado = 'vencido';
        }
        $this->update(['estado' => $estado]);
    }
}
