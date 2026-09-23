<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Ejercicio extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'desde', 'hasta', 'estado', 'cerrado_en', 'asientos'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'cerrado_en' => 'datetime', 'asientos' => 'array'];
}
