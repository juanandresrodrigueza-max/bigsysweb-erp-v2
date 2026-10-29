<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 36.4: un trabajo que se repite (service de 10.000 km, cambio de pantalla) con sus repuestos y mano de obra.
class ServicioPlantilla extends Model
{
    use BelongsToBusiness;

    protected $table = 'servicio_plantillas';
    protected $fillable = ['business_id', 'nombre', 'items'];
    protected $casts = ['items' => 'array'];
}
