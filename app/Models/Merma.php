<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 89: lo que se pierde con nombre y apellido: vencido, roto, se tiró, error de cocina, cortesía, consumo del personal.
class Merma extends Model
{
    use BelongsToBusiness;

    public const MOTIVOS = ['vencido' => 'Vencido', 'roto' => 'Roto o dañado', 'tirado' => 'Se tiró', 'error_cocina' => 'Error de cocina', 'cortesia' => 'Cortesía', 'consumo_personal' => 'Consumo del personal', 'otro' => 'Otro'];

    protected $fillable = ['business_id', 'business_location_id', 'product_id', 'deposito_id', 'user_id', 'comanda_item_id', 'fecha', 'cantidad', 'costo_unit', 'motivo', 'notas'];
    protected $casts = ['fecha' => 'date', 'cantidad' => 'decimal:3', 'costo_unit' => 'decimal:2'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function deposito(): BelongsTo { return $this->belongsTo(Deposito::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function importe(): float { return round((float) $this->cantidad * (float) $this->costo_unit, 2); }
}
