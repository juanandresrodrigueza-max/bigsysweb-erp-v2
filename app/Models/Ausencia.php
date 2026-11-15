<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Fase 98: una ausencia o licencia de un empleado: vacaciones, enfermedad, examen, falta, suspensión…
class Ausencia extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'empleado_id', 'tipo', 'desde', 'hasta', 'dias', 'estado', 'anio_vacaciones', 'certificado', 'notas', 'creada_por', 'aprobada_por'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'dias' => 'decimal:1', 'certificado' => 'boolean'];

    // [label, cómo se cuentan los días, qué le hace a la liquidación]
    // corridos: todos los días del tramo (vacaciones, licencias de la LCT). habiles: sin domingos ni feriados (faltas, suspensiones).
    // 'vacaciones' va a la columna vacaciones del recibo; 'descuenta' baja los días trabajados; 'paga' no toca el recibo (la paga el empleador igual).
    public const TIPOS = [
        'vacaciones'          => ['label' => 'Vacaciones',                       'conteo' => 'corridos', 'efecto' => 'vacaciones'],
        'enfermedad'          => ['label' => 'Enfermedad', 'conteo' => 'corridos', 'efecto' => 'paga'],
        'accidente_trabajo'   => ['label' => 'Accidente de trabajo (ART)',        'conteo' => 'corridos', 'efecto' => 'paga'],
        'examen'              => ['label' => 'Examen',    'conteo' => 'corridos', 'efecto' => 'paga'],
        'matrimonio'          => ['label' => 'Matrimonio',      'conteo' => 'corridos', 'efecto' => 'paga'],
        'nacimiento'          => ['label' => 'Nacimiento de hijo',                'conteo' => 'corridos', 'efecto' => 'paga'],
        'fallecimiento'       => ['label' => 'Fallecimiento de familiar',         'conteo' => 'corridos', 'efecto' => 'paga'],
        'maternidad'          => ['label' => 'Maternidad (ANSES)',   'conteo' => 'corridos', 'efecto' => 'descuenta'],
        'donacion_sangre'     => ['label' => 'Donación de sangre',                'conteo' => 'corridos', 'efecto' => 'paga'],
        'mudanza'             => ['label' => 'Mudanza',                           'conteo' => 'corridos', 'efecto' => 'paga'],
        'tramite'             => ['label' => 'Trámite judicial',       'conteo' => 'corridos', 'efecto' => 'paga'],
        'sin_goce'            => ['label' => 'Sin goce de sueldo',       'conteo' => 'habiles',  'efecto' => 'descuenta'],
        'falta_injustificada' => ['label' => 'Falta injustificada',               'conteo' => 'habiles',  'efecto' => 'descuenta'],
        'suspension'          => ['label' => 'Suspensión',                        'conteo' => 'habiles',  'efecto' => 'descuenta'],
        'otro'                => ['label' => 'Otra licencia paga',                'conteo' => 'corridos', 'efecto' => 'paga'],
    ];
    public const ESTADOS = ['pendiente' => 'Pendiente', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada'];

    public function empleado() { return $this->belongsTo(Empleado::class); }
    public function tipoLabel(): string { return self::TIPOS[$this->tipo]['label'] ?? $this->tipo; }
    public static function efecto(string $tipo): string { return self::TIPOS[$tipo]['efecto'] ?? 'paga'; }
}
