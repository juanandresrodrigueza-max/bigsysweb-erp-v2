<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespachoItem extends Model
{
    protected $fillable = ['despacho_id', 'product_id', 'descripcion', 'cantidad', 'fob_unit', 'fob_total_ars', 'gastos_ars', 'costo_unit_ars', 'orden'];
    protected $casts = ['cantidad' => 'decimal:3', 'fob_unit' => 'decimal:4', 'fob_total_ars' => 'decimal:2', 'gastos_ars' => 'decimal:2', 'costo_unit_ars' => 'decimal:4'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function despacho(): BelongsTo { return $this->belongsTo(DespachoImportacion::class, 'despacho_id'); }
}
