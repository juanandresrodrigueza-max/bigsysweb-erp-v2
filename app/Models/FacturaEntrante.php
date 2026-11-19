<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 120.3: una factura que llegó a la casilla (por mail o subida de a varias). Queda leída y a la espera de que alguien la revise.
class FacturaEntrante extends Model
{
    use BelongsToBusiness;

    protected $table = 'facturas_entrantes';
    protected $fillable = ['business_id', 'origen', 'remitente', 'asunto', 'archivo', 'ruta', 'mime', 'hash', 'message_id', 'estado', 'detalle', 'borrador_id', 'compra_id', 'proveedor', 'numero', 'total'];
    protected $casts = ['total' => 'float'];

    public const ESTADOS = [
        'lista' => 'Para revisar', 'cargada' => 'Cargada', 'duplicada' => 'Repetida', 'sin_leer' => 'No se pudo leer',
        'sin_empresa' => 'Sin empresa', 'sin_adjunto' => 'Sin factura adjunta', 'descartada' => 'Descartada',
    ];
}
