<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// Trazabilidad: cuánto entró o salió de un lote, por qué comprobante y para qué cliente o proveedor.
class LoteMovimiento extends Model
{
    use BelongsToBusiness;

    protected $table = 'lote_movimientos';
    protected $fillable = ['business_id', 'lote_id', 'product_id', 'stock_movement_id', 'cantidad', 'movable_type', 'movable_id', 'contact_id', 'motivo'];
    protected $casts = ['cantidad' => 'decimal:3'];

    public function lote(): BelongsTo { return $this->belongsTo(Lote::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function movable(): MorphTo { return $this->morphTo(); }
}
