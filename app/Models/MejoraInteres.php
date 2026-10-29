<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MejoraInteres extends Model
{
    protected $table = 'mejora_intereses';
    protected $guarded = [];
    protected $casts = ['ultimo_envio' => 'datetime', 'publicada_en' => 'datetime', 'atendido_en' => 'datetime'];
}
