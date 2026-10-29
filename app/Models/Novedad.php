<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Novedad extends Model
{
    protected $table = 'novedades';
    protected $guarded = [];
    protected $casts = ['ultimo_envio' => 'datetime', 'publicada_en' => 'datetime', 'atendido_en' => 'datetime'];
}
