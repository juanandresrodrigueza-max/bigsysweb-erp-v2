<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class OrdenTrabajoFoto extends Model
{
    use BelongsToBusiness;

    protected $table = 'orden_trabajo_fotos';
    protected $fillable = ['business_id', 'orden_trabajo_id', 'user_id', 'momento', 'path', 'nota'];
    public const MOMENTOS = ['recepcion' => 'Recepción', 'antes' => 'Antes', 'durante' => 'Durante', 'despues' => 'Después'];
}
