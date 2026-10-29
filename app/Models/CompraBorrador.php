<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Una factura de compra a medio cargar: la lectura, lo que se corrigió y los archivos, para retomarla desde cualquier computadora sin volver a leerla.
class CompraBorrador extends Model
{
    use BelongsToBusiness;

    protected $table = 'compras_borradores';
    protected $fillable = ['business_id', 'user_id', 'contact_id', 'proveedor', 'numero', 'total', 'datos', 'adjuntos'];
    protected $casts = ['datos' => 'array', 'adjuntos' => 'array', 'total' => 'float'];
}
