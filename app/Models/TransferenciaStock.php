<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferenciaStock extends Model
{
    use BelongsToBusiness;

    protected $table = 'transferencias_stock';
    protected $fillable = ['business_id', 'business_location_id', 'numero', 'fecha', 'origen_id', 'destino_id', 'user_id', 'estado', 'notas'];
    protected $casts = ['fecha' => 'date'];

    public function origen(): BelongsTo { return $this->belongsTo(Deposito::class, 'origen_id'); }
    public function destino(): BelongsTo { return $this->belongsTo(Deposito::class, 'destino_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(TransferenciaStockItem::class, 'transferencia_id'); }

    public function numeroFormateado(): string { return sprintf('TR %06d', $this->numero); }
}
