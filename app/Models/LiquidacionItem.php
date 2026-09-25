<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiquidacionItem extends Model
{
    protected $fillable = ['liquidacion_id', 'empleado_id', 'dias', 'horas_extra_50', 'horas_extra_100', 'adicionales', 'no_rem_extra', 'anticipos', 'bruto', 'no_rem', 'deducciones', 'neto', 'contribuciones', 'detalle', 'feriados', 'vacaciones', 'redondeo'];
    protected $casts = ['detalle' => 'array', 'horas_extra_50' => 'decimal:2', 'horas_extra_100' => 'decimal:2', 'adicionales' => 'decimal:2', 'no_rem_extra' => 'decimal:2', 'anticipos' => 'decimal:2', 'bruto' => 'decimal:2', 'no_rem' => 'decimal:2', 'deducciones' => 'decimal:2', 'neto' => 'decimal:2', 'contribuciones' => 'decimal:2', 'redondeo' => 'decimal:2'];

    public function liquidacion() { return $this->belongsTo(Liquidacion::class); }
    public function empleado() { return $this->belongsTo(Empleado::class); }
}
