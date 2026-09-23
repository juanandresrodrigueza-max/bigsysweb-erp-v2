<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Habitacion extends Model
{
    use BelongsToBusiness;

    public const TIPOS = ['single' => 'Single', 'doble' => 'Doble', 'triple' => 'Triple', 'cuadruple' => 'Cuádruple', 'suite' => 'Suite', 'cabania' => 'Cabaña', 'dormi' => 'Dormi / compartida'];
    public const ESTADOS = ['libre' => 'Libre', 'ocupada' => 'Ocupada', 'limpieza' => 'En limpieza', 'mantenimiento' => 'Mantenimiento'];

    protected $table = 'habitaciones';
    protected $fillable = ['business_id', 'business_location_id', 'nombre', 'tipo', 'capacidad', 'tarifa', 'piso', 'estado', 'activa', 'orden'];
    protected $casts = ['tarifa' => 'decimal:2', 'activa' => 'boolean'];

    public function estadias() { return $this->hasMany(Estadia::class); }
}
