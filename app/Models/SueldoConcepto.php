<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Concepto de liquidación: haber, no remunerativo, deducción del empleado o contribución patronal.
// Cálculo: porcentaje (valor % × base), fijo (valor) o división (base ÷ valor × cantidad, ej. básico ÷ 30 × días).
// Base: expresión con BASICO, REM (remunerativo calculado hasta ahí), NOREM o códigos de concepto sumados con "+".
class SueldoConcepto extends Model
{
    use BelongsToBusiness;

    public const TIPOS = ['haber' => 'Haber remunerativo', 'no_remunerativo' => 'No remunerativo', 'deduccion' => 'Deducción (aporte del empleado)', 'contribucion' => 'Contribución patronal'];
    public const MODOS = ['porcentaje' => '% de la base', 'fijo' => 'Importe fijo', 'division' => 'Base ÷ valor × cantidad'];
    public const CANTIDADES = ['' => '1', 'dias' => 'Días trabajados', 'feriados' => 'Feriados no trabajados', 'vacaciones' => 'Días de vacaciones', 'anios' => 'Años de antigüedad', 'horas_extra_50' => 'Horas extra 50%', 'horas_extra_100' => 'Horas extra 100%'];
    public const GRUPOS = ['seg_social' => 'Seguridad social', 'obra_social' => 'Obra social', 'art' => 'ART', 'sindicato' => 'Sindicato y convenio', 'otro' => 'Otros'];

    // Conceptos por defecto (ley 24241, 19032, 23660 y contribuciones patronales generales). Se pueden ajustar por empresa.
    public const DEFAULT = [
        ['ANT', 'Antigüedad (1% por año)', 'haber', 'porcentaje', 1, 'BASICO', 10],
        ['PRE', 'Presentismo', 'haber', 'porcentaje', 8.33, 'BASICO', 20],
        ['JUB', 'Jubilación (ley 24241)', 'deduccion', 'porcentaje', 11, 'REM', 30],
        ['L19', 'Ley 19032 (INSSJP)', 'deduccion', 'porcentaje', 3, 'REM', 31],
        ['OS', 'Obra social', 'deduccion', 'porcentaje', 3, 'REM', 32],
        ['SIN', 'Cuota sindical', 'deduccion', 'porcentaje', 2, 'REM', 33],
        ['CJU', 'Contribución jubilatoria', 'contribucion', 'porcentaje', 12.35, 'REM', 40],
        ['CL19', 'Contribución ley 19032', 'contribucion', 'porcentaje', 1.5, 'REM', 41],
        ['COS', 'Contribución obra social', 'contribucion', 'porcentaje', 6, 'REM', 42],
        ['ART', 'ART', 'contribucion', 'porcentaje', 3, 'REM', 43],
        ['ASF', 'Asignaciones familiares y fondo de empleo', 'contribucion', 'porcentaje', 5.4, 'REM', 44],
    ];

    // Empleados de Comercio (CCT 130/75) como los liquida el estudio: reproduce los recibos de agosto 2026.
    // Las sumas del acuerdo (1200 y 1238) son de jornada completa y se prorratean por jornada: actualizalas con cada acuerdo.
    // [codigo, nombre, tipo, modo, valor, base, orden, extras]
    public const PLANTILLA_COMERCIO = [
        ['1000', 'DIAS NORMALES', 'haber', 'division', 30, 'BASICO', 10, ['cantidad' => 'dias']],
        ['1042', 'DIAS FERIADOS NO TRABAJADOS', 'haber', 'division', 25, 'BASICO', 11, ['cantidad' => 'feriados']],
        ['1051', 'DIAS VACACIONES', 'haber', 'division', 25, 'BASICO', 12, ['cantidad' => 'vacaciones']],
        ['1102', 'ANTIGUEDAD COMERCIO', 'haber', 'porcentaje', 1, '1000+1042+1051', 13, ['por_anio' => true, 'cantidad' => 'anios']],
        ['1295', 'PRESENTISMO 8.33 %', 'haber', 'division', 12, 'REM', 19, ['etiqueta' => '8.33 %']],
        ['1200', 'NO REMUNERATIVO', 'no_remunerativo', 'fijo', 25000, 'BASICO', 20, ['proporcional_jornada' => true]],
        ['1238', 'INCREMENTO NO REMUNERATIVO', 'no_remunerativo', 'fijo', 130000, 'BASICO', 21, ['proporcional_jornada' => true, 'mas_antiguedad' => 1]],
        ['2000', 'JUBILACION 11%', 'deduccion', 'porcentaje', 11, 'REM', 30, ['grupo' => 'seg_social']],
        ['2001', 'INSSJP 3%', 'deduccion', 'porcentaje', 3, 'REM', 31, ['grupo' => 'seg_social']],
        ['2100', 'OBRA SOCIAL 2.48%', 'deduccion', 'porcentaje', 2.48, 'REM+1238', 32, ['grupo' => 'obra_social', 'jornada_completa' => true]],
        ['2104', 'ANSSAL 0.52%', 'deduccion', 'porcentaje', 0.52, 'REM+1238', 33, ['grupo' => 'obra_social', 'jornada_completa' => true]],
        ['2120', 'OBRA SOCIAL CUOTA EXTRA', 'deduccion', 'fijo', 100, 'BASICO', 34, ['grupo' => 'obra_social']],
        ['2150', 'SINDICATO 2%', 'deduccion', 'porcentaje', 2, 'REM+1238', 35, ['grupo' => 'sindicato']],
        ['2151', 'SEGURO ASISTENCIAL CEC 1%', 'deduccion', 'porcentaje', 1, 'REM+1238', 36, ['grupo' => 'sindicato', 'solo_asignados' => true]],
        ['2152', 'FAECYS 0.5%', 'deduccion', 'porcentaje', 0.5, 'REM+1238', 37, ['grupo' => 'sindicato']],
        ['5000', 'SIJP 10.7685%', 'contribucion', 'porcentaje', 10.7685, 'REM', 50, ['grupo' => 'seg_social', 'con_detraccion' => true]],
        ['5002', 'INSSJP 1.5883%', 'contribucion', 'porcentaje', 1.5883, 'REM', 51, ['grupo' => 'seg_social', 'con_detraccion' => true]],
        ['5003', 'ASIG.FAMILIARES 4.7013%', 'contribucion', 'porcentaje', 4.7013, 'REM', 52, ['grupo' => 'seg_social', 'con_detraccion' => true]],
        ['5004', 'FNE 0.9424%', 'contribucion', 'porcentaje', 0.9424, 'REM', 53, ['grupo' => 'seg_social', 'con_detraccion' => true]],
        ['5040', 'ART APORTE FIJO', 'contribucion', 'fijo', 1827, 'BASICO', 54, ['grupo' => 'art']],
        ['5041', 'ART APORTE PORCENTUAL', 'contribucion', 'porcentaje', 3.04, 'REM+NOREM', 55, ['grupo' => 'art']],
        ['5100', 'OBRA SOCIAL 4.95%', 'contribucion', 'porcentaje', 4.95, 'REM+1238', 56, ['grupo' => 'obra_social', 'jornada_completa' => true]],
        ['5101', 'ANSSAL 1.05%', 'contribucion', 'porcentaje', 1.05, 'REM+1238', 57, ['grupo' => 'obra_social', 'jornada_completa' => true]],
        ['5905', 'SEGURO DE VIDA OBLIGATORIO', 'contribucion', 'fijo', 424.62, 'BASICO', 58, ['grupo' => 'otro']],
        ['5907', 'CONTRIBUCION EXC. OSECAC', 'contribucion', 'fijo', 28000, 'BASICO', 59, ['grupo' => 'obra_social']],
    ];
    public const PLANTILLAS = ['comercio' => 'Comercio · CCT 130/75 (FAECYS, OSECAC)'];

    protected $fillable = ['business_id', 'codigo', 'nombre', 'tipo', 'modo', 'valor', 'base', 'activo', 'orden', 'cantidad', 'por_anio', 'mas_antiguedad', 'proporcional_jornada', 'jornada_completa', 'con_detraccion', 'grupo', 'solo_asignados', 'etiqueta'];
    protected $casts = ['valor' => 'decimal:4', 'activo' => 'boolean', 'por_anio' => 'boolean', 'mas_antiguedad' => 'decimal:3', 'proporcional_jornada' => 'boolean', 'jornada_completa' => 'boolean', 'con_detraccion' => 'boolean', 'solo_asignados' => 'boolean'];

    public static function crearDefault(int $businessId): void
    {
        if (self::withoutGlobalScopes()->where('business_id', $businessId)->exists()) return;
        foreach (self::DEFAULT as [$cod, $nom, $tipo, $modo, $valor, $base, $orden]) self::withoutGlobalScopes()->create(['business_id' => $businessId, 'codigo' => $cod, 'nombre' => $nom, 'tipo' => $tipo, 'modo' => $modo, 'valor' => $valor, 'base' => $base, 'orden' => $orden, 'por_anio' => $cod === 'ANT']);
    }

    // Carga una plantilla de convenio: desactiva los conceptos actuales y crea (o reactiva) los de la plantilla.
    public static function cargarPlantilla(int $businessId, string $plantilla): int
    {
        $filas = match ($plantilla) { 'comercio' => self::PLANTILLA_COMERCIO, default => throw new \InvalidArgumentException('Plantilla desconocida') };
        self::withoutGlobalScopes()->where('business_id', $businessId)->update(['activo' => false]);
        foreach ($filas as [$cod, $nom, $tipo, $modo, $valor, $base, $orden, $extra]) {
            self::withoutGlobalScopes()->updateOrCreate(['business_id' => $businessId, 'codigo' => $cod], array_replace(
                ['nombre' => $nom, 'tipo' => $tipo, 'modo' => $modo, 'valor' => $valor, 'base' => $base, 'orden' => $orden, 'activo' => true, 'cantidad' => null, 'por_anio' => false, 'mas_antiguedad' => 0, 'proporcional_jornada' => false, 'jornada_completa' => false, 'con_detraccion' => false, 'grupo' => null, 'solo_asignados' => false, 'etiqueta' => null],
                $extra));
        }
        return count($filas);
    }
}
