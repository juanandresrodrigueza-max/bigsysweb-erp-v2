<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 119.5: algo que Bigcito tiene que recordar. Con user_id es de esa persona; sin user_id vale para toda la empresa.
class BigcitoMemoria extends Model
{
    use BelongsToBusiness;

    protected $table = 'bigcito_memorias';
    protected $fillable = ['business_id', 'user_id', 'creada_por', 'texto'];
}
