<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivoAmortizacion extends Model
{
    protected $table = 'activo_amortizaciones';
    protected $fillable = ['activo_fijo_id', 'periodo', 'monto', 'asiento_id'];
    protected $casts = ['monto' => 'decimal:2'];

    public function activo() { return $this->belongsTo(ActivoFijo::class, 'activo_fijo_id'); }
}
