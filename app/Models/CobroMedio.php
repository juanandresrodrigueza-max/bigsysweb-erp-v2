<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CobroMedio extends Model
{
    public $timestamps = false;
    protected $fillable = ['cobro_id', 'medio', 'monto', 'referencia', 'datos'];
    protected $casts = ['monto' => 'decimal:2', 'datos' => 'array'];
}
