<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDeposito extends Model
{
    use BelongsToBusiness;

    protected $table = 'stock_depositos';
    protected $fillable = ['business_id', 'product_id', 'deposito_id', 'cantidad', 'stock_min', 'ubicacion'];
    protected $casts = ['cantidad' => 'decimal:3', 'stock_min' => 'decimal:3'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }
}
