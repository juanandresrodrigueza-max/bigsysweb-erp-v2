<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorSistema extends Model
{
    protected $table = 'errores_sistema';
    protected $fillable = ['hash', 'clase', 'mensaje', 'archivo', 'linea', 'ruta', 'metodo', 'business_id', 'user_id', 'veces', 'primera_vez', 'ultima_vez', 'resuelto_en', 'enviado_sentry', 'traza'];
    protected $casts = ['primera_vez' => 'datetime', 'ultima_vez' => 'datetime', 'resuelto_en' => 'datetime', 'enviado_sentry' => 'boolean'];
}
