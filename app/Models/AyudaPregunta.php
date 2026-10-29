<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AyudaPregunta extends Model
{
    protected $table = 'ayuda_preguntas';
    protected $fillable = ['business_id', 'user_id', 'pregunta', 'respuesta', 'fuentes', 'sin_respuesta', 'util'];
    protected $casts = ['fuentes' => 'array', 'sin_respuesta' => 'boolean', 'util' => 'boolean'];
}
