<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcopioRetiroItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['acopio_retiro_id', 'acopio_item_id', 'cantidad'];
    protected $casts = ['cantidad' => 'decimal:3'];

    public function acopioItem(): BelongsTo { return $this->belongsTo(AcopioItem::class); }
}
