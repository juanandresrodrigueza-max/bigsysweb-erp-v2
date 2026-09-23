<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comprobante extends Model
{
    use BelongsToBusiness, SoftDeletes;

    // tipo => [nombre, letra, afip_id (null = no fiscal), signo en CC (+1 debe, -1 haber, 0 no impacta), mueve_stock]
    public const TIPOS = [
        'FA'  => ['nombre' => 'Factura A',          'letra' => 'A', 'afip' => 1,    'cc' => 1,  'stock' => true,  'grupo' => 'factura'],
        'FB'  => ['nombre' => 'Factura B',          'letra' => 'B', 'afip' => 6,    'cc' => 1,  'stock' => true,  'grupo' => 'factura'],
        'FC'  => ['nombre' => 'Factura C',          'letra' => 'C', 'afip' => 11,   'cc' => 1,  'stock' => true,  'grupo' => 'factura'],
        'FE'  => ['nombre' => 'Factura E',          'letra' => 'E', 'afip' => 19,   'cc' => 1,  'stock' => true,  'grupo' => 'factura'],
        'NDA' => ['nombre' => 'Nota de Débito A',   'letra' => 'A', 'afip' => 2,    'cc' => 1,  'stock' => false, 'grupo' => 'nd'],
        'NDB' => ['nombre' => 'Nota de Débito B',   'letra' => 'B', 'afip' => 7,    'cc' => 1,  'stock' => false, 'grupo' => 'nd'],
        'NDC' => ['nombre' => 'Nota de Débito C',   'letra' => 'C', 'afip' => 12,   'cc' => 1,  'stock' => false, 'grupo' => 'nd'],
        'NCA' => ['nombre' => 'Nota de Crédito A',  'letra' => 'A', 'afip' => 3,    'cc' => -1, 'stock' => true,  'grupo' => 'nc'],
        'NCB' => ['nombre' => 'Nota de Crédito B',  'letra' => 'B', 'afip' => 8,    'cc' => -1, 'stock' => true,  'grupo' => 'nc'],
        'NCC' => ['nombre' => 'Nota de Crédito C',  'letra' => 'C', 'afip' => 13,   'cc' => -1, 'stock' => true,  'grupo' => 'nc'],
        'REM' => ['nombre' => 'Remito',             'letra' => 'R', 'afip' => null, 'cc' => 0,  'stock' => true,  'grupo' => 'remito'],
        'PRE' => ['nombre' => 'Presupuesto',        'letra' => 'P', 'afip' => null, 'cc' => 0,  'stock' => false, 'grupo' => 'presupuesto'],
    ];

    protected $fillable = [
        'business_id', 'business_location_id', 'contact_id', 'user_id', 'vendedor_id', 'orden_compra_id', 'abono_id', 'punto_venta_id', 'origen_id', 'entrega_pendiente', 'fce', 'fce_estado', 'fce_vto_pago', 'public_token', 'aprobado_en', 'rechazado_en', 'respuesta_cliente', 'link_pago', 'link_pago_id',
        'direccion', 'tipo', 'punto_venta', 'numero', 'fecha', 'fecha_vto', 'condicion', 'moneda', 'cotizacion',
        'neto', 'exento', 'iva', 'percepciones', 'descuento', 'total', 'saldo', 'estado', 'afip_estado',
        'cae', 'cae_vto', 'afip_respuesta', 'es_acopio', 'stock_impactado', 'notas', 'pdf_path', 'emitido_en', 'anulado_en',
        'numero_proveedor', 'cae_proveedor', 'origen_carga',
    ];

    protected $casts = [
        'fecha' => 'date', 'fecha_vto' => 'date', 'cae_vto' => 'date', 'emitido_en' => 'datetime', 'anulado_en' => 'datetime',
        'afip_respuesta' => 'array', 'es_acopio' => 'boolean', 'stock_impactado' => 'boolean', 'entrega_pendiente' => 'boolean', 'fce' => 'boolean', 'fce_vto_pago' => 'date', 'aprobado_en' => 'datetime', 'rechazado_en' => 'datetime',
        'neto' => 'decimal:2', 'exento' => 'decimal:2', 'iva' => 'decimal:2', 'percepciones' => 'decimal:2',
        'descuento' => 'decimal:2', 'total' => 'decimal:2', 'saldo' => 'decimal:2', 'cotizacion' => 'decimal:4',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function vendedor(): BelongsTo { return $this->belongsTo(Vendedor::class); }
    public function ordenCompra(): BelongsTo { return $this->belongsTo(OrdenCompra::class); }
    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
    public function puntoVenta(): BelongsTo { return $this->belongsTo(PuntoVenta::class); }
    public function origen(): BelongsTo { return $this->belongsTo(Comprobante::class, 'origen_id'); }
    public function derivados(): HasMany { return $this->hasMany(Comprobante::class, 'origen_id'); }
    public function items(): HasMany { return $this->hasMany(ComprobanteItem::class)->orderBy('orden'); }
    public function impuestos(): HasMany { return $this->hasMany(ComprobanteImpuesto::class); }
    public function imputaciones(): HasMany { return $this->hasMany(CobroImputacion::class); }
    public function acopio(): HasOne { return $this->hasOne(Acopio::class); }
    public function adjuntos(): HasMany { return $this->hasMany(ComprobanteAdjunto::class); }
    public function abono(): BelongsTo { return $this->belongsTo(Abono::class); }
    public function envios(): HasMany { return $this->hasMany(Envio::class, 'modelo_id')->where('modelo', 'Comprobante'); }

    // Token para el link público (ver, aprobar presupuesto, pagar). Se crea la primera vez que se pide.
    public function tokenPublico(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => \Illuminate\Support\Str::random(32)])->save();
        }
        return $this->public_token;
    }
    public function urlPublica(): string { return url('/p/' . $this->tokenPublico()); }

    // Pendientes de entrega (factura con entrega pendiente) o de facturación (remito) por ítem.
    public function pendienteEntrega(): float { return $this->entrega_pendiente && $this->esFactura() && $this->estado === 'emitido' ? round($this->items->sum(fn($i) => max(0, (float) $i->cantidad - (float) $i->cantidad_entregada)), 3) : 0; }
    public function pendienteFacturar(): float { return $this->tipo === 'REM' && $this->estado === 'emitido' ? round($this->items->sum(fn($i) => max(0, (float) $i->cantidad - (float) $i->cantidad_facturada)), 3) : 0; }

    public function scopeVentas(Builder $q): Builder { return $q->where('direccion', 'venta'); }
    public function scopeCompras(Builder $q): Builder { return $q->where('direccion', 'compra'); }
    public function pagosImputados(): HasMany { return $this->hasMany(PagoImputacion::class); }
    public function scopePendientesPago(Builder $q): Builder { return $q->compras()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005); }
    public function scopeEmitidos(Builder $q): Builder { return $q->where('estado', 'emitido'); }
    public function scopeFacturas(Builder $q): Builder { return $q->whereIn('tipo', ['FA', 'FB', 'FC', 'FE']); }
    public function scopePendientesCobro(Builder $q): Builder { return $q->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005); }

    public function def(): array { return self::TIPOS[$this->tipo] ?? ['nombre' => $this->tipo, 'letra' => '', 'afip' => null, 'cc' => 0, 'stock' => false, 'grupo' => 'otro']; }
    public function esFiscal(): bool { return $this->def()['afip'] !== null; }
    public function esFactura(): bool { return $this->def()['grupo'] === 'factura'; }
    public function esNotaCredito(): bool { return $this->def()['grupo'] === 'nc'; }
    public function nombreTipo(): string { return $this->fce ? str_replace(['Factura', 'Nota de Crédito', 'Nota de Débito'], ['FCE MiPyME', 'NC FCE MiPyME', 'ND FCE MiPyME'], $this->def()['nombre']) : $this->def()['nombre']; }
    // Código AFIP: las FCE usan 201/206/211 (y sus NC/ND) en lugar de 1/6/11.
    public function afipTipo(): ?int { $t = $this->def()['afip']; if (! $t || ! $this->fce) return $t; return match ($this->def()['grupo']) { 'factura' => ['A' => 201, 'B' => 206, 'C' => 211][$this->def()['letra']] ?? $t, 'nd' => ['A' => 202, 'B' => 207, 'C' => 212][$this->def()['letra']] ?? $t, 'nc' => ['A' => 203, 'B' => 208, 'C' => 213][$this->def()['letra']] ?? $t, default => $t }; }

    public function numeroFormateado(): ?string
    {
        if ($this->direccion === 'compra') {
            return $this->numero_proveedor ?: ($this->numero ? sprintf('%04d-%08d', $this->punto_venta ?? 0, $this->numero) : null);
        }
        if (! $this->numero) {
            return null;
        }
        return sprintf('%04d-%08d', $this->punto_venta ?? 0, $this->numero);
    }

    public function estadoCobro(): string
    {
        if ($this->def()['cc'] <= 0 || $this->estado !== 'emitido') {
            return 'na';
        }
        if ((float) $this->saldo <= 0.005) {
            return 'cobrado';
        }
        return (float) $this->saldo < (float) $this->total ? 'parcial' : 'pendiente';
    }

    public function vencido(): bool
    {
        return $this->estadoCobro() === 'pendiente' && $this->fecha_vto && $this->fecha_vto->lt(today());
    }

    public function recalcularTotales(): void
    {
        $items = $this->items()->get();
        $neto = $items->sum(fn($i) => (float) $i->neto);
        $iva  = $items->sum(fn($i) => (float) $i->iva);
        $percep = (float) $this->impuestos()->where('tipo', 'like', 'iibb%')->sum('monto');
        $total = round($neto + $iva + $percep, 2);
        $this->forceFill([
            'neto' => round($neto, 2), 'iva' => round($iva, 2), 'percepciones' => $percep, 'total' => $total,
            'saldo' => $this->estado === 'emitido' && $this->def()['cc'] > 0 ? round($total - (float) $this->imputaciones()->sum('monto'), 2) : ($this->def()['cc'] > 0 ? $total : 0),
        ])->save();
    }
}
