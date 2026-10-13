<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Acción que la IA propuso y espera la confirmación del usuario. Vence a los 30 minutos.
class IaPropuesta extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $table = 'ia_propuestas';
    protected $fillable = ['business_id', 'user_id', 'conexion_id', 'origen', 'herramienta', 'titulo', 'detalle', 'datos', 'estado', 'resultado', 'expira_en', 'ejecutada_en'];
    protected $casts = ['datos' => 'array', 'resultado' => 'array', 'expira_en' => 'datetime', 'ejecutada_en' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
