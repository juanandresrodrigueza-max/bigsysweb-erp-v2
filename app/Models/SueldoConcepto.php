<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Concepto de liquidación: haber, no remunerativo, deducción del empleado o contribución patronal.
class SueldoConcepto extends Model
{
    use BelongsToBusiness;

    public const TIPOS = ['haber' => 'Haber remunerativo', 'no_remunerativo' => 'No remunerativo', 'deduccion' => 'Deducción (aporte del empleado)', 'contribucion' => 'Contribución patronal'];

    // Conceptos por defecto (ley 24241, 19032, 23660 y contribuciones patronales generales). Se pueden ajustar por empresa.
    public const DEFAULT = [
        ['ANT', 'Antigüedad (1% por año)', 'haber', 'porcentaje', 1, 'basico', 10],
        ['PRE', 'Presentismo', 'haber', 'porcentaje', 8.33, 'basico', 20],
        ['JUB', 'Jubilación (ley 24241)', 'deduccion', 'porcentaje', 11, 'bruto', 30],
        ['L19', 'Ley 19032 (INSSJP)', 'deduccion', 'porcentaje', 3, 'bruto', 31],
        ['OS', 'Obra social', 'deduccion', 'porcentaje', 3, 'bruto', 32],
        ['SIN', 'Cuota sindical', 'deduccion', 'porcentaje', 2, 'bruto', 33],
        ['CJU', 'Contribución jubilatoria', 'contribucion', 'porcentaje', 12.35, 'bruto', 40],
        ['CL19', 'Contribución ley 19032', 'contribucion', 'porcentaje', 1.5, 'bruto', 41],
        ['COS', 'Contribución obra social', 'contribucion', 'porcentaje', 6, 'bruto', 42],
        ['ART', 'ART', 'contribucion', 'porcentaje', 3, 'bruto', 43],
        ['ASF', 'Asignaciones familiares y fondo de empleo', 'contribucion', 'porcentaje', 5.4, 'bruto', 44],
    ];

    protected $fillable = ['business_id', 'codigo', 'nombre', 'tipo', 'modo', 'valor', 'base', 'activo', 'orden'];
    protected $casts = ['valor' => 'decimal:4', 'activo' => 'boolean'];

    public static function crearDefault(int $businessId): void
    {
        if (self::withoutGlobalScopes()->where('business_id', $businessId)->exists()) return;
        foreach (self::DEFAULT as [$cod, $nom, $tipo, $modo, $valor, $base, $orden]) self::withoutGlobalScopes()->create(['business_id' => $businessId, 'codigo' => $cod, 'nombre' => $nom, 'tipo' => $tipo, 'modo' => $modo, 'valor' => $valor, 'base' => $base, 'orden' => $orden]);
    }
}
