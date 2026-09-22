<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteImpuesto extends Model
{
    public $timestamps = false;
    protected $fillable = ['comprobante_id', 'tipo', 'base', 'alicuota', 'monto'];
}
