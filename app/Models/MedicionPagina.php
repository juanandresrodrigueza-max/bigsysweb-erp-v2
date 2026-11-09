<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 77: una página medida una noche (tiempo, consultas, las más lentas).
class MedicionPagina extends Model
{
    protected $table = 'mediciones_paginas';
    public $timestamps = false;
    protected $fillable = ['business_id', 'url', 'ms', 'consultas', 'sql_ms', 'http', 'lentas', 'ronda', 'created_at'];
    protected $casts = ['lentas' => 'array', 'created_at' => 'datetime'];
}
