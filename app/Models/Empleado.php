<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Empleado en relación de dependencia (legajo).
class Empleado extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'legajo', 'nombre', 'cuil', 'categoria', 'convenio', 'puesto', 'fecha_ingreso', 'fecha_egreso', 'sueldo_basico', 'modalidad', 'obra_social', 'cbu', 'email', 'telefono', 'activo', 'notas'];
    protected $casts = ['fecha_ingreso' => 'date', 'fecha_egreso' => 'date', 'sueldo_basico' => 'decimal:2', 'activo' => 'boolean'];

    public function location() { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(LiquidacionItem::class); }

    public function antiguedadAnios(?\Carbon\Carbon $a = null): int { return (int) $this->fecha_ingreso->diffInYears($a ?? today()); }
}
