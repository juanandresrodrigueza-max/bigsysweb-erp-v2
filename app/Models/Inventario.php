<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Conteo físico de un depósito: lo contado contra lo que decía el sistema, con los ajustes que generó.
class Inventario extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'deposito_id', 'user_id', 'numero', 'fecha', 'estado', 'items_contados', 'items_con_diferencia', 'diferencia_valorizada', 'notas'];
    protected $casts = ['fecha' => 'date', 'diferencia_valorizada' => 'decimal:2'];

    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(InventarioItem::class); }

    public function numeroFormateado(): string { return sprintf('INV %06d', $this->numero); }
}
