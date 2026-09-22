<?php
namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'product_id', 'user_id', 'type', 'quantity', 'stock_before', 'stock_after', 'reason', 'movable_id', 'movable_type'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
