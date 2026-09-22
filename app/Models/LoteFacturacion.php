<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class LoteFacturacion extends Model
{
    use BelongsToBusiness;

    protected $table = 'lotes_facturacion';
    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'fecha', 'origen', 'cantidad', 'emitidos', 'con_error', 'estado', 'detalle'];
    protected $casts = ['fecha' => 'date', 'detalle' => 'array'];
}
