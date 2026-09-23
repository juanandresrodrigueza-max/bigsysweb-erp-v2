<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Ejercicio extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'desde', 'hasta', 'estado', 'cerrado_en', 'asientos'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'cerrado_en' => 'datetime', 'asientos' => 'array'];
}
