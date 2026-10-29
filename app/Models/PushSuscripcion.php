<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSuscripcion extends Model
{
    protected $table = 'push_suscripciones';
    protected $guarded = [];
    protected $casts = ['ultimo_envio' => 'datetime', 'publicada_en' => 'datetime', 'atendido_en' => 'datetime'];
}
