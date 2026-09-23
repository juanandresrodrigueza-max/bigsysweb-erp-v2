<?php

namespace App\Services\Fondos;

use App\Models\Abono;
use App\Models\Business;
use App\Models\Cheque;
use App\Models\Comprobante;
use App\Models\CuentaFondos;
use App\Models\MovimientoFondos;
use Illuminate\Support\Carbon;

// Cash flow proyectado a 13 semanas: con lo que hay en caja y bancos, lo que está por cobrar y lo que hay que pagar.
class CashFlowService
{
    public function proyectar(Business $b, int $semanas = 13): array
    {
        $hoy = today();
        $saldo = (float) CuentaFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('activa', true)->whereIn('tipo', ['caja', 'banco', 'billetera'])->sum('saldo');
        $sem = fn(?Carbon $f) => $f && $f->gt($hoy) ? min($semanas - 1, (int) floor($hoy->diffInDays($f) / 7)) : 0; // vencido → esta semana

        $filas = ['cobros' => ['label' => 'Cobros de facturas por vencer', 'tipo' => 'in'], 'cheques_in' => ['label' => 'Cheques en cartera a cobrar', 'tipo' => 'in'], 'abonos' => ['label' => 'Abonos recurrentes', 'tipo' => 'in'], 'ventas_contado' => ['label' => 'Ventas de contado estimadas', 'tipo' => 'in'],
            'pagos' => ['label' => 'Facturas de compra a pagar', 'tipo' => 'out'], 'cheques_out' => ['label' => 'Cheques propios a debitar', 'tipo' => 'out'], 'gastos' => ['label' => 'Gastos recurrentes estimados', 'tipo' => 'out'], 'sueldos' => ['label' => 'Sueldos y cargas sociales', 'tipo' => 'out'], 'compras_estimadas' => ['label' => 'Compras estimadas (reposición)', 'tipo' => 'out']];
        foreach ($filas as &$f) $f['semanas'] = array_fill(0, $semanas, 0.0);
        unset($f);

        // Cobranza pendiente: facturas de venta con saldo, ponderadas por mora histórica (se cobra el 85% en fecha, el resto se corre dos semanas)
        foreach (Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'])->where('saldo', '>', 0.005)->get(['saldo', 'fecha_vto']) as $c) {
            $s = $sem($c->fecha_vto); $filas['cobros']['semanas'][$s] += (float) $c->saldo * 0.85; $filas['cobros']['semanas'][min($semanas - 1, $s + 2)] += (float) $c->saldo * 0.15;
        }
        foreach (Cheque::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'tercero')->where('estado', 'cartera')->get(['monto', 'fecha_pago']) as $ch) $filas['cheques_in']['semanas'][$sem($ch->fecha_pago)] += (float) $ch->monto;
        foreach (Abono::withoutGlobalScopes()->where('business_id', $b->id)->where('activo', true)->get() as $a) { $prox = $a->proximo ? Carbon::parse($a->proximo) : null; $imp = $a->importe(); for ($i = 0; $i < 4 && $prox; $i++) { if ($prox->gt($hoy->copy()->addWeeks($semanas))) break; $filas['abonos']['semanas'][$sem($prox)] += $imp; $prox = $prox->copy()->addMonth(); } }
        // Ventas de contado: promedio semanal de los últimos 90 días de cobros en efectivo/tarjeta/transferencia ligados a facturas contado
        $contado = (float) Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC'])->where('condicion', 'contado')->where('fecha', '>=', $hoy->copy()->subDays(90))->sum('total') / 13;
        for ($i = 0; $i < $semanas; $i++) $filas['ventas_contado']['semanas'][$i] = round($contado, 2);

        foreach (Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->where('saldo', '>', 0.005)->get(['saldo', 'fecha_vto']) as $c) $filas['pagos']['semanas'][$sem($c->fecha_vto)] += (float) $c->saldo;
        foreach (Cheque::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'propio')->where('estado', 'entregado')->get(['monto', 'fecha_pago']) as $ch) $filas['cheques_out']['semanas'][$sem($ch->fecha_pago)] += (float) $ch->monto;
        $gastos = (float) MovimientoFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('origen', 'gasto')->where('fecha', '>=', $hoy->copy()->subDays(90))->sum('egreso') / 13;
        $compras = (float) Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', 'emitido')->where('fecha', '>=', $hoy->copy()->subDays(90))->sum('total') / 13;
        for ($i = 0; $i < $semanas; $i++) { $filas['gastos']['semanas'][$i] = round($gastos, 2); $filas['compras_estimadas']['semanas'][$i] = $i < 2 ? 0 : round($compras * 0.6, 2); } // las primeras semanas ya están en "a pagar"

        // Sueldos: la última liquidación se repite cada mes (neto el día de pago, cargas a mitad de mes siguiente).
        if ($liq = \App\Models\Liquidacion::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'mensual')->where('estado', '!=', 'borrador')->orderByDesc('periodo')->first()) {
            $dia = (int) (($b->sueldos['dia_pago'] ?? 4)); $cargas = round((float) $liq->total_deducciones + (float) $liq->total_contribuciones, 2);
            for ($m = 0; $m < 4; $m++) { $fp = $hoy->copy()->addMonths($m)->day(min($dia, 28)); if ($fp->lt($hoy)) continue; if ($fp->gt($hoy->copy()->addWeeks($semanas))) break; $filas['sueldos']['semanas'][$sem($fp)] += (float) $liq->total_neto; $fc = $fp->copy()->day(15); if ($fc->lte($hoy->copy()->addWeeks($semanas))) $filas['sueldos']['semanas'][$sem($fc)] += $cargas; }
        }
        $cols = []; $acum = $saldo; $minimo = ['saldo' => $saldo, 'semana' => 0];
        for ($i = 0; $i < $semanas; $i++) {
            $in = array_sum(array_map(fn($f) => $f['tipo'] === 'in' ? $f['semanas'][$i] : 0, $filas)); $out = array_sum(array_map(fn($f) => $f['tipo'] === 'out' ? $f['semanas'][$i] : 0, $filas));
            $acum = round($acum + $in - $out, 2);
            if ($acum < $minimo['saldo']) $minimo = ['saldo' => $acum, 'semana' => $i];
            $d = $hoy->copy()->addWeeks($i);
            $cols[] = ['semana' => $i, 'label' => 'Sem ' . $d->isoWeek, 'desde' => $d->format('d/m'), 'hasta' => $d->copy()->addDays(6)->format('d/m'), 'ingresos' => round($in, 2), 'egresos' => round($out, 2), 'neto' => round($in - $out, 2), 'saldo' => $acum];
        }
        foreach ($filas as &$f) { $f['semanas'] = array_map(fn($v) => round($v, 2), $f['semanas']); $f['total'] = round(array_sum($f['semanas']), 2); }
        return ['saldo_inicial' => round($saldo, 2), 'columnas' => $cols, 'filas' => $filas, 'minimo' => $minimo, 'alerta' => $minimo['saldo'] < 0 ? "Con este ritmo, la caja queda en negativo en la semana " . ($minimo['semana'] + 1) . " (" . $cols[$minimo['semana']]['desde'] . "). Adelantá cobranzas o postergá pagos." : null];
    }
}
