<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UbicacionStock extends Model
{
    use BelongsToBusiness;

    protected $table = 'ubicacion_stock';
    protected $fillable = ['business_id', 'ubicacion_id', 'product_id', 'lote_id', 'cantidad'];
    protected $casts = ['cantidad' => 'decimal:3'];

    public function ubicacion(): BelongsTo { return $this->belongsTo(Ubicacion::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function lote(): BelongsTo { return $this->belongsTo(Lote::class); }
}
