<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Fase 107: una propuesta que dejó un agente de Bigcito. Se aprueba o rechaza; aprobar ejecuta la acción.
class Propuesta extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'agente', 'clave', 'titulo', 'detalle', 'datos', 'accion', 'importe', 'prioridad', 'estado', 'resultado', 'motivo', 'resuelta_en', 'resuelta_por'];
    protected $casts = ['datos' => 'array', 'accion' => 'array', 'importe' => 'decimal:2', 'resuelta_en' => 'datetime'];

    public function scopePendientes(Builder $q): Builder { return $q->where('estado', 'pendiente'); }

    public function resueltaPor() { return $this->belongsTo(User::class, 'resuelta_por'); }

    public function soloAviso(): bool { return empty($this->accion); }
}
