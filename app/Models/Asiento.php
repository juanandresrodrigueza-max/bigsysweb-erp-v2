<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asiento extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const ORIGENES = ['venta' => 'Venta', 'compra' => 'Compra', 'cobro' => 'Cobro', 'pago' => 'Pago', 'fondos' => 'Fondos', 'manual' => 'Manual'];

    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'numero', 'fecha', 'concepto', 'origen', 'origen_id', 'total', 'estado'];
    protected $casts = ['fecha' => 'date', 'total' => 'decimal:2'];

    public function lineas(): HasMany { return $this->hasMany(AsientoLinea::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function scopeConfirmados(Builder $q): Builder { return $q->where('estado', 'confirmado'); }

    public function numeroFormateado(): string { return sprintf('AS %06d', $this->numero); }

    // Link a la pantalla del comprobante u operación que lo generó.
    public function urlOrigen(): ?string
    {
        return match ($this->origen) {
            'venta' => "/comprobantes/{$this->origen_id}",
            'compra' => "/proveedores/compras/{$this->origen_id}",
            'cobro' => "/clientes/cobros/{$this->origen_id}/imprimir",
            'pago' => "/proveedores/pagos/{$this->origen_id}/imprimir",
            'fondos' => '/fondos',
            default => null,
        };
    }
}
