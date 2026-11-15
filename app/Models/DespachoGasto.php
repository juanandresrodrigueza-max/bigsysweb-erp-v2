<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespachoGasto extends Model
{
    protected $fillable = ['despacho_id', 'tipo', 'concepto', 'monto', 'al_costo'];
    protected $casts = ['monto' => 'decimal:2', 'al_costo' => 'boolean'];
}
