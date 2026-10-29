<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Un comprador pidió ver o borrar sus datos (ley de datos personales). Borrar lo hace el sistema; ver lo contesta el dueño.
class PedidoDatosPersonales extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;
    protected $table = 'pedidos_datos_personales';
    protected $fillable = ['business_id', 'canal_id', 'tipo', 'cliente', 'pedidos', 'estado', 'hecho_at', 'hecho_por', 'created_at'];
    protected $casts = ['cliente' => 'array', 'pedidos' => 'array', 'hecho_at' => 'datetime', 'created_at' => 'datetime'];
}
