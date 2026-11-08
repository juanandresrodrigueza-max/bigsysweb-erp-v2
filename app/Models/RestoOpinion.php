<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 56: lo que opinó el cliente al pagar (1 a 5 estrellas y un comentario), atado a la comanda, la mesa y el mozo.
class RestoOpinion extends Model
{
    use BelongsToBusiness;
    protected $table = 'resto_opiniones';
    protected $fillable = ['business_id', 'comanda_id', 'mesa_id', 'user_id', 'puntaje', 'comentario', 'canal'];
    public function comanda() { return $this->belongsTo(Comanda::class); }
    public function mozo() { return $this->belongsTo(User::class, 'user_id'); }
}
