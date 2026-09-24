<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcopioRetiro extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    protected $fillable = ['acopio_id', 'remito_id', 'user_id', 'fecha', 'retirado_por', 'observaciones'];
    protected $casts = ['fecha' => 'date'];

    public function acopio(): BelongsTo { return $this->belongsTo(Acopio::class); }
    public function remito(): BelongsTo { return $this->belongsTo(Comprobante::class, 'remito_id'); }
    public function items(): HasMany { return $this->hasMany(AcopioRetiroItem::class); }
}
