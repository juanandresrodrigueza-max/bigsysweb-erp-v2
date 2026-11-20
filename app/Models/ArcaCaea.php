<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fase 126: un CAEA de ARCA para una quincena (periodo AAAAMM + orden 1 o 2), por CUIT y ambiente.
class ArcaCaea extends Model
{
    protected $table = 'arca_caeas';
    protected $fillable = ['business_id', 'cuit', 'produccion', 'periodo', 'orden', 'caea', 'desde', 'hasta', 'tope_informar', 'sin_movimiento'];
    protected $casts = ['produccion' => 'boolean', 'desde' => 'date', 'hasta' => 'date', 'tope_informar' => 'date', 'sin_movimiento' => 'array'];

    public function business() { return $this->belongsTo(Business::class); }
    public function quincena(): string { return ($this->orden === 1 ? '1.ª' : '2.ª') . ' quincena de ' . \Carbon\Carbon::createFromFormat('Ymd', $this->periodo . '01')->locale('es')->translatedFormat('F Y'); }
}
