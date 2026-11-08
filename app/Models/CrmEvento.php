<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 68 · Un renglón por cada cosa que viajó (o intentó viajar) entre el ERP y el CRM. Sin scope de empresa: lo escriben jobs sin usuario.
class CrmEvento extends Model
{
    public $timestamps = false;
    protected $table = 'crm_eventos';
    protected $fillable = ['business_id', 'direccion', 'evento', 'referencia', 'estado', 'http', 'ms', 'detalle', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
