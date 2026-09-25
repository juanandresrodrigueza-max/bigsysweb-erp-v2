<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Partida de stock con lote, vencimiento o número de serie. Se consume FEFO (vence primero, sale primero).
class Lote extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'product_id', 'deposito_id', 'lote', 'serie', 'vencimiento', 'cantidad', 'costo_unit', 'estado', 'motivo', 'proveedor_id', 'ingreso'];
    protected $casts = ['vencimiento' => 'date', 'cantidad' => 'decimal:3', 'costo_unit' => 'decimal:4', 'ingreso' => 'date'];

    public const ESTADOS = ['disponible' => 'Disponible', 'bloqueado' => 'Bloqueado', 'retirado' => 'Retirado del mercado'];

    public function movimientos(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(LoteMovimiento::class)->orderBy('id'); }
    public function proveedor(): BelongsTo { return $this->belongsTo(Contact::class, 'proveedor_id'); }
    public function vencido(): bool { return $this->vencimiento !== null && $this->vencimiento->lt(today()); }
    public function vendible(): bool { return $this->estado === 'disponible' && ! $this->vencido(); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }
    public function etiqueta(): string { return trim(($this->serie ? "S/N {$this->serie}" : ($this->lote ? "Lote {$this->lote}" : 'Sin lote')) . ($this->vencimiento ? ' · vence ' . $this->vencimiento->format('d/m/Y') : '')); }
}
