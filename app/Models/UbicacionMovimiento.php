<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UbicacionMovimiento extends Model
{
    use BelongsToBusiness;

    protected $table = 'ubicacion_movimientos';
    protected $fillable = ['business_id', 'user_id', 'product_id', 'lote_id', 'desde_id', 'hacia_id', 'cantidad', 'motivo'];
    protected $casts = ['cantidad' => 'decimal:3'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function desde(): BelongsTo { return $this->belongsTo(Ubicacion::class, 'desde_id'); }
    public function hacia(): BelongsTo { return $this->belongsTo(Ubicacion::class, 'hacia_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
