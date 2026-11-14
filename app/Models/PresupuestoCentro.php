<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 96: el presupuesto de gastos de un mes, por centro de costo (o de toda la empresa si centro_costo_id es null).
class PresupuestoCentro extends Model
{
    use BelongsToBusiness;

    protected $table = 'presupuestos_centro';
    protected $fillable = ['business_id', 'centro_costo_id', 'anio', 'mes', 'monto'];
    protected $casts = ['monto' => 'decimal:2'];
}
