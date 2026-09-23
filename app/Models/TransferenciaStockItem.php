<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaStockItem extends Model
{
    public $timestamps = false;
    protected $table = 'transferencia_stock_items';
    protected $fillable = ['transferencia_id', 'product_id', 'cantidad'];
    protected $casts = ['cantidad' => 'decimal:3'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
