<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 38.2: una hoja del PDF de sueldos que liquidó otro programa o el contador, asignada a su empleado.
class ReciboExterno extends Model
{
    use BelongsToBusiness;

    protected $table = 'recibos_externos';
    protected $fillable = ['business_id', 'empleado_id', 'periodo', 'archivo_origen', 'hash_archivo', 'pagina', 'hash_pagina', 'path', 'texto', 'identificado_por', 'user_id', 'token', 'enviado_en', 'visto_en', 'firmado_en', 'firma', 'firma_ip', 'firma_agente', 'conformidad', 'observacion'];
    protected $casts = ['enviado_en' => 'datetime', 'visto_en' => 'datetime', 'firmado_en' => 'datetime'];
    protected $hidden = ['firma'];

    public function empleado() { return $this->belongsTo(Empleado::class); }
}
