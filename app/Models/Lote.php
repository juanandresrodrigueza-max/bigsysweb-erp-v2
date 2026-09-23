<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Partida de stock con lote, vencimiento o número de serie. Se consume FEFO (vence primero, sale primero).
class Lote extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'product_id', 'deposito_id', 'lote', 'serie', 'vencimiento', 'cantidad', 'costo_unit'];
    protected $casts = ['vencimiento' => 'date', 'cantidad' => 'decimal:3', 'costo_unit' => 'decimal:4'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }
    public function etiqueta(): string { return trim(($this->serie ? "S/N {$this->serie}" : ($this->lote ? "Lote {$this->lote}" : 'Sin lote')) . ($this->vencimiento ? ' · vence ' . $this->vencimiento->format('d/m/Y') : '')); }
}
