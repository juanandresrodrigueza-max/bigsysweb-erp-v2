<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 98: feriados propios de la empresa (y los nacionales que esta empresa decide trabajar: tipo "quitado"). Los nacionales se calculan solos.
class Feriado extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'fecha', 'nombre', 'tipo'];
    protected $casts = ['fecha' => 'date'];

    public const TIPOS = ['propio' => 'Feriado propio de la empresa', 'puente' => 'Puente turístico', 'no_laborable' => 'Día no laborable', 'quitado' => 'Feriado nacional que trabajamos'];
}
