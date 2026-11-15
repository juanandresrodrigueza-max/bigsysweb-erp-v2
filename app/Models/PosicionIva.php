<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 99: la posición mensual de IVA de la empresa, con el saldo a favor que se arrastra al mes siguiente.
class PosicionIva extends Model
{
    use BelongsToBusiness;

    protected $table = 'posiciones_iva';
    protected $fillable = ['business_id', 'mes', 'debito', 'credito', 'percepciones', 'retenciones', 'saldo_anterior_tecnico', 'saldo_anterior_libre', 'ajuste', 'ajuste_nota', 'a_pagar', 'saldo_tecnico', 'saldo_libre', 'estado', 'detalle', 'cerrada_por', 'cerrada_en'];
    protected $casts = ['detalle' => 'array', 'cerrada_en' => 'datetime', 'debito' => 'decimal:2', 'credito' => 'decimal:2', 'percepciones' => 'decimal:2', 'retenciones' => 'decimal:2', 'saldo_anterior_tecnico' => 'decimal:2', 'saldo_anterior_libre' => 'decimal:2', 'ajuste' => 'decimal:2', 'a_pagar' => 'decimal:2', 'saldo_tecnico' => 'decimal:2', 'saldo_libre' => 'decimal:2'];
}
