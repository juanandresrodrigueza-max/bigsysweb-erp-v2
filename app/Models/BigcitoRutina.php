<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fase 119.4: algo que el usuario le dejó encargado a Bigcito para que lo haga solo, cada tanto.
class BigcitoRutina extends Model
{
    use BelongsToBusiness;

    protected $table = 'bigcito_rutinas';
    protected $fillable = ['business_id', 'user_id', 'nombre', 'instruccion', 'frecuencia', 'dias', 'dia_mes', 'hora', 'canal', 'solo_si_hay', 'activa', 'proxima_en', 'ultima_en', 'ultimo_resultado', 'propuestas', 'ejecuciones'];
    protected $casts = ['dias' => 'array', 'propuestas' => 'array', 'solo_si_hay' => 'boolean', 'activa' => 'boolean', 'proxima_en' => 'datetime', 'ultima_en' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
