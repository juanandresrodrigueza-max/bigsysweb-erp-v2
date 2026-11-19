<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 120.1: cuándo escribió por última vez un número a la empresa y qué tiene pendiente de recibir. Se usa desde
// el webhook (sin sesión), así que no lleva el filtro por empresa del usuario: siempre se consulta con business_id.
class WhatsappVentana extends Model
{
    protected $table = 'whatsapp_ventanas';
    protected $fillable = ['business_id', 'telefono', 'ultimo_entrante_en', 'pendientes', 'ultimo_saliente'];
    protected $casts = ['ultimo_entrante_en' => 'datetime', 'pendientes' => 'array', 'ultimo_saliente' => 'array'];
}
