<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenCompra extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $table = 'ordenes_compra';
    protected $fillable = ['business_id', 'business_location_id', 'contact_id', 'user_id', 'numero', 'fecha', 'fecha_entrega', 'estado', 'total', 'notas', 'origen', 'enviada_en'];
    protected $casts = ['fecha' => 'date', 'fecha_entrega' => 'date', 'enviada_en' => 'datetime', 'total' => 'decimal:2'];

    public const ESTADOS = ['borrador' => 'Borrador', 'enviada' => 'Enviada', 'parcial' => 'Recibida parcial', 'recibida' => 'Recibida', 'cancelada' => 'Cancelada'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
    public function items(): HasMany { return $this->hasMany(OrdenCompraItem::class)->orderBy('orden'); }
    public function compras(): HasMany { return $this->hasMany(Comprobante::class, 'orden_compra_id'); }

    public function numeroFormateado(): string { return sprintf('OC-%06d', $this->numero); }
    public function abierta(): bool { return in_array($this->estado, ['borrador', 'enviada', 'parcial'], true); }

    public function recalcular(): void
    {
        $this->forceFill(['total' => round($this->items()->get()->sum(fn($i) => (float) $i->cantidad * (float) $i->precio_unit), 2)])->save();
    }
}
