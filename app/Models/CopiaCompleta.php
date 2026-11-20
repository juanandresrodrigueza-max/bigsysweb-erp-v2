<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 123: una copia completa de la base de datos (de todo el sistema, no de una empresa).
class CopiaCompleta extends Model
{
    protected $table = 'copias_completas';
    protected $guarded = ['id'];
    protected $casts = ['verificada' => 'boolean', 'podada' => 'boolean'];
}
