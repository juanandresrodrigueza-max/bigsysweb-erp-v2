<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventarioItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['inventario_id', 'product_id', 'sistema', 'contado', 'diferencia', 'costo_unit'];
    protected $casts = ['sistema' => 'decimal:3', 'contado' => 'decimal:3', 'diferencia' => 'decimal:3', 'costo_unit' => 'decimal:2'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
