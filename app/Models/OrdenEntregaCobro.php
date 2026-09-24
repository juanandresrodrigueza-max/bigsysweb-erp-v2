<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Lo que el chofer cobró en una entrega, antes de rendir el viaje.
class OrdenEntregaCobro extends Model
{
    use BelongsToBusiness;

    public const MEDIOS = ['efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'transferencia' => 'Transferencia', 'mercadopago' => 'Mercado Pago'];

    protected $table = 'orden_entrega_cobros';
    protected $fillable = ['business_id', 'orden_entrega_id', 'orden_entrega_item_id', 'comprobante_id', 'contact_id', 'medio', 'monto', 'referencia', 'datos', 'cobro_id', 'user_id'];
    protected $casts = ['monto' => 'decimal:2', 'datos' => 'array'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function comprobante(): BelongsTo { return $this->belongsTo(Comprobante::class); }
    public function cobro(): BelongsTo { return $this->belongsTo(Cobro::class); }
}
