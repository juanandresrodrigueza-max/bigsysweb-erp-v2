<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// Kardex: cada entrada o salida de un artículo en un depósito, con quién, por qué y contra qué comprobante.
class StockMovement extends Model
{
    use BelongsToBusiness;

    public const TIPOS = ['in' => 'Entrada', 'out' => 'Salida', 'ajuste' => 'Ajuste', 'transferencia' => 'Transferencia', 'inventario' => 'Inventario', 'produccion' => 'Producción'];

    protected $fillable = ['business_id', 'business_location_id', 'product_id', 'deposito_id', 'user_id', 'type', 'quantity', 'stock_before', 'stock_after', 'costo_unit', 'reason', 'movable_id', 'movable_type'];
    protected $casts = ['quantity' => 'decimal:3', 'stock_before' => 'decimal:3', 'stock_after' => 'decimal:3', 'costo_unit' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movable(): MorphTo
    {
        return $this->morphTo();
    }
}
