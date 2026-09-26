<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespieceCorte extends Model
{
    public $timestamps = false;
    protected $fillable = ['despiece_id', 'product_id', 'rinde', 'orden'];
    protected $casts = ['rinde' => 'decimal:3'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
