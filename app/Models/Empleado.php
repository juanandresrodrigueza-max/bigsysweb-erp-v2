<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Empleado en relación de dependencia (legajo).
class Empleado extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'business_location_id', 'user_id', 'legajo', 'nombre', 'cuil', 'categoria', 'convenio', 'puesto', 'fecha_ingreso', 'fecha_egreso', 'sueldo_basico', 'modalidad', 'obra_social', 'cbu', 'email', 'telefono', 'activo', 'notas', 'documento', 'centro_costo', 'lugar_trabajo', 'jornada'];
    protected $casts = ['fecha_ingreso' => 'date', 'fecha_egreso' => 'date', 'sueldo_basico' => 'decimal:2', 'activo' => 'boolean', 'jornada' => 'decimal:3'];

    public const JORNADAS = ['1' => 'Jornada completa', '0.75' => '3/4 de jornada', '0.5' => 'Media jornada', '0.333' => '1/3 de jornada'];

    public function location() { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(LiquidacionItem::class); }
    // Conceptos que se le aplican solo a él (afiliado al sindicato, seguro de convenio, embargos…), con valor propio opcional.
    public function conceptos() { return $this->belongsToMany(SueldoConcepto::class, 'empleado_conceptos')->withPivot('valor'); }
    public function jornadaTexto(): string { return self::JORNADAS[rtrim(rtrim(number_format((float) $this->jornada, 3, '.', ''), '0'), '.')] ?? (round((float) $this->jornada * 100) . '% de jornada'); }

    public function antiguedadAnios(?\Carbon\Carbon $a = null): int { return (int) $this->fecha_ingreso->diffInYears($a ?? today()); }
}
