<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 39.2: un grupo de empresas con nombre ("Todo el grupo") para mirarlas juntas.
class ConjuntoEmpresas extends Model
{
    protected $table = 'conjuntos_empresas';
    protected $fillable = ['user_id', 'nombre', 'empresas'];
    protected $casts = ['empresas' => 'array'];
}
