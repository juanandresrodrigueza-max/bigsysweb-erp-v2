<?php

namespace App\Services\Sueldos;

use App\Models\Empleado;
use App\Models\LiquidacionItem;
use Carbon\Carbon;

// Fase 98: retención del impuesto a las ganancias de 4ta categoría (RG 4003 / ley 27.743), acumulada mes a mes.
// La tabla (mínimo no imponible, deducción especial, cargas de familia y la escala del art. 94) la carga el contador en Preferencias de sueldos:
// ARCA la actualiza por semestre. Las deducciones de cada empleado vienen de su SIRADIG (F.572) y se cargan en la ficha.
class GananciasService
{
    public const CODIGO = 'GAN';

    public static function config(array $cfg): array
    {
        $g = (array) ($cfg['ganancias'] ?? []);
        return ['activo' => (bool) ($g['activo'] ?? false), 'mni' => (float) ($g['mni'] ?? 0), 'deduccion_especial' => (float) ($g['deduccion_especial'] ?? 0), 'conyuge' => (float) ($g['conyuge'] ?? 0), 'hijo' => (float) ($g['hijo'] ?? 0),
            'tope_alquiler' => (float) ($g['tope_alquiler'] ?? 0), 'tope_domestico' => (float) ($g['tope_domestico'] ?? 0), 'escala' => array_values(array_map(fn($t) => ['desde' => (float) ($t['desde'] ?? 0), 'hasta' => $t['hasta'] === null || $t['hasta'] === '' ? null : (float) $t['hasta'], 'fijo' => (float) ($t['fijo'] ?? 0), 'pct' => (float) ($t['pct'] ?? 0)], (array) ($g['escala'] ?? [])))];
    }

    // Retención del mes: impuesto acumulado al mes (ganancia neta acumulada − deducciones proporcionales) menos lo ya retenido en el año.
    // $remMes: remuneración bruta del mes (rem + no rem); $aportesMes: jubilación, obra social, PAMI y sindicato del mes.
    public function retencion(Empleado $e, Carbon $periodo, float $remMes, float $aportesMes, array $cfg, ?int $excluirItem = null): array
    {
        $g = self::config($cfg);
        if (! $g['activo'] || ! $g['escala']) return ['monto' => 0, 'detalle' => null];
        $mes = (int) $periodo->month; $anio = (int) $periodo->year;
        $previos = LiquidacionItem::where('empleado_id', $e->id)->when($excluirItem, fn($q) => $q->where('id', '!=', $excluirItem))
            ->whereHas('liquidacion', fn($q) => $q->where('periodo', '>=', "{$anio}-01")->where('periodo', '<', $periodo->format('Y-m')))->get(['bruto', 'no_rem', 'detalle']);
        $remAcum = $remMes + (float) $previos->sum(fn($i) => (float) $i->bruto + (float) $i->no_rem);
        $aportesAcum = $aportesMes + (float) $previos->sum(fn($i) => collect($i->detalle ?? [])->where('tipo', 'deduccion')->whereNotIn('codigo', ['ANTI', self::CODIGO])->sum('monto'));
        $retenidoAcum = (float) $previos->sum(fn($i) => collect($i->detalle ?? [])->where('codigo', self::CODIGO)->sum('monto'));
        $ded = (array) ($e->ganancias ?? []);
        $prop = $mes / 12;
        $deducciones = ['mni' => $g['mni'] * $prop, 'especial' => $g['deduccion_especial'] * $prop];
        if (! empty($ded['conyuge'])) $deducciones['conyuge'] = $g['conyuge'] * $prop;
        if (($h = (int) ($ded['hijos'] ?? 0)) > 0) $deducciones['hijos'] = $g['hijo'] * $h * $prop;
        if (($alq = (float) ($ded['alquiler'] ?? 0)) > 0) $deducciones['alquiler'] = min($alq * 0.4 * $mes, $g['tope_alquiler'] > 0 ? $g['tope_alquiler'] * $prop : PHP_FLOAT_MAX);
        if (($dom = (float) ($ded['servicio_domestico'] ?? 0)) > 0) $deducciones['domestico'] = min($dom * $mes, $g['tope_domestico'] > 0 ? $g['tope_domestico'] * $prop : PHP_FLOAT_MAX);
        if (($otras = (float) ($ded['otras'] ?? 0)) > 0) $deducciones['otras'] = $otras * $mes;
        $neta = max(0, $remAcum - $aportesAcum - array_sum($deducciones));
        $impuesto = 0.0;
        foreach ($g['escala'] as $t) {
            $desde = $t['desde'] * $prop; $hasta = $t['hasta'] === null ? null : $t['hasta'] * $prop;
            if ($neta > $desde && ($hasta === null || $neta <= $hasta)) { $impuesto = $t['fijo'] * $prop + ($neta - $desde) * $t['pct'] / 100; break; }
        }
        $monto = round($impuesto - $retenidoAcum, 2);
        return ['monto' => $monto, 'detalle' => ['rem_acum' => round($remAcum, 2), 'aportes_acum' => round($aportesAcum, 2), 'deducciones' => array_map(fn($v) => round($v, 2), $deducciones), 'neta_acum' => round($neta, 2), 'impuesto_acum' => round($impuesto, 2), 'retenido_antes' => round($retenidoAcum, 2), 'mes' => $mes]];
    }
}
