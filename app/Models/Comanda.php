<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Una comanda: la cuenta de una mesa, un pedido de mostrador o un delivery. Al cerrar se convierte en comprobante.
class Comanda extends Model
{
    use BelongsToBusiness;

    public const TIPOS = ['mesa' => 'Mesa', 'mostrador' => 'Mostrador', 'delivery' => 'Delivery'];
    public const ESTADOS = ['abierta' => 'Abierta', 'cuenta' => 'Pidió la cuenta', 'cerrada' => 'Cerrada', 'anulada' => 'Anulada'];

    protected $fillable = ['business_id', 'business_location_id', 'mesa_id', 'user_id', 'comprobante_id', 'numero', 'tipo', 'estado', 'cubiertos', 'cliente', 'direccion', 'telefono', 'total', 'propina', 'descuento', 'notas', 'abierta_en', 'cuenta_en', 'cerrada_en'];
    protected $casts = ['total' => 'decimal:2', 'propina' => 'decimal:2', 'descuento' => 'decimal:2', 'abierta_en' => 'datetime', 'cuenta_en' => 'datetime', 'cerrada_en' => 'datetime'];

    public function mesa(): BelongsTo { return $this->belongsTo(Mesa::class); }
    public function mozo(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function items(): HasMany { return $this->hasMany(ComandaItem::class)->orderBy('ronda')->orderBy('id'); }

    public function scopeAbiertas(Builder $q): Builder { return $q->whereIn('estado', ['abierta', 'cuenta']); }

    public function numeroFormateado(): string { return sprintf('#%05d', $this->numero); }

    public function titulo(): string
    {
        return match ($this->tipo) { 'mesa' => 'Mesa ' . ($this->mesa?->nombre ?? '?'), 'delivery' => 'Delivery · ' . ($this->cliente ?: 'sin nombre'), default => 'Mostrador · ' . ($this->cliente ?: $this->numeroFormateado()) };
    }

    public function recalcular(): void
    {
        // precio_unit ya es el precio final de carta (IVA incluido).
        $t = $this->items()->where('estado', '!=', 'anulado')->get()->sum(fn($i) => (float) $i->cantidad * (float) $i->precio_unit);
        $this->forceFill(['total' => round($t - (float) $this->descuento, 2)])->save();
    }
}
