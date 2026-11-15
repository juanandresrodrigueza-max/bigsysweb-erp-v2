<?php

namespace App\Services\Contabilidad;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\CuentaFondos;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Fase 99: los indicadores que el contador mira antes de hablar con el dueño: 12 meses de ventas, compras, IVA y gastos, margen, días de cobro y pago,
// liquidez, presión fiscal y la salud de la carga (qué falta asentar, conciliar o categorizar).
class IndicadoresContadorService
{
    private const VENTA = ['FA', 'FB', 'FC', 'FE', 'FM', 'NDA', 'NDB', 'NDC', 'NDM']; private const NC = ['NCA', 'NCB', 'NCC', 'NCE', 'NCM'];

    public function tablero(Business $b, ?string $mes = null): array
    {
        $mes ??= now()->format('Y-m'); $bid = $b->id; $fin = Carbon::parse($mes . '-01')->endOfMonth();
        $serie = [];
        for ($i = 11; $i >= 0; $i--) { $m = $fin->copy()->startOfMonth()->subMonths($i); $serie[] = $this->mes($bid, $m); }
        $act = end($serie); $ant = $serie[count($serie) - 2] ?? $act;
        $ventas12 = round(array_sum(array_column($serie, 'ventas_neto')), 2); $compras12 = round(array_sum(array_column($serie, 'compras_neto')), 2); $gastos12 = round(array_sum(array_column($serie, 'gastos')), 2);
        // Días de cobro y de pago (sobre los últimos 90 días de ventas y compras).
        $d90 = $fin->copy()->subDays(89)->toDateString(); $h = $fin->toDateString();
        $v90 = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->fiscales()->whereIn('tipo', self::VENTA)->whereBetween('fecha', [$d90, $h])->sum('total');
        $c90 = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$d90, $h])->sum('total');
        $porCobrar = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->where('saldo', '>', 0.005)->sum('saldo');
        $vencido = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->where('saldo', '>', 0.005)->where('fecha_vto', '<', $h)->sum('saldo');
        $porPagar = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'compra')->where('estado', 'emitido')->where('saldo', '>', 0.005)->sum('saldo');
        $cuentas = CuentaFondos::withoutGlobalScopes()->where('business_id', $bid)->where('activa', true)->whereIn('tipo', ['caja', 'banco', 'billetera'])->get();
        $disponible = round((float) $cuentas->sum('saldo'), 2);
        $margen = $ventas12 > 0 ? round(($ventas12 - $compras12) / $ventas12 * 100, 1) : null;
        $resultado12 = round($ventas12 - $compras12 - $gastos12, 2);
        $ivaPagado12 = round(array_sum(array_map(fn($s) => max(0, $s['iva_debito'] - $s['iva_credito']), $serie)), 2);
        $salud = [];
        $sinAsiento = DB::table('comprobantes')->where('business_id', $bid)->where('estado', 'emitido')->whereBetween('fecha', [$fin->copy()->startOfMonth()->toDateString(), $h])->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('asientos')->whereColumn('asientos.origen_id', 'comprobantes.id')->where('asientos.origen', 'comprobante'))->count();
        if ($sinAsiento) $salud[] = ['texto' => "{$sinAsiento} comprobantes del mes sin asiento", 'url' => '/contable'];
        $sinConc = ExtractoItem::withoutGlobalScopes()->where('business_id', $bid)->where('estado', 'pendiente')->count(); if ($sinConc) $salud[] = ['texto' => "{$sinConc} renglones del banco sin conciliar", 'url' => '/contable/conciliacion'];
        $sinCat = MovimientoFondos::withoutGlobalScopes()->where('business_id', $bid)->where('origen', 'gasto')->whereNull('expense_category_id')->whereBetween('fecha', [$fin->copy()->startOfMonth()->toDateString(), $h])->count(); if ($sinCat) $salud[] = ['texto' => "{$sinCat} gastos del mes sin categoría", 'url' => '/fondos'];
        $sinCae = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('afip_estado', 'pendiente')->count(); if ($sinCae) $salud[] = ['texto' => "{$sinCae} comprobantes sin CAE", 'url' => '/comprobantes?afip=pendiente'];
        $ivaAbierta = \App\Models\PosicionIva::withoutGlobalScopes()->where('business_id', $bid)->where('mes', '<', $mes)->where('estado', '!=', 'cerrada')->count(); if ($ivaAbierta) $salud[] = ['texto' => "{$ivaAbierta} posiciones de IVA de meses anteriores sin cerrar", 'url' => '/contable/iva/posicion'];
        return ['mes' => $mes, 'periodo' => ucfirst($fin->locale('es')->isoFormat('MMMM YYYY')), 'serie' => $serie,
            'kpis' => ['ventas_mes' => $act['ventas_neto'], 'ventas_var' => $ant['ventas_neto'] > 0 ? round(($act['ventas_neto'] - $ant['ventas_neto']) / $ant['ventas_neto'] * 100) : null, 'ventas_12' => $ventas12, 'compras_12' => $compras12, 'gastos_12' => $gastos12, 'resultado_12' => $resultado12, 'margen_bruto' => $margen,
                'dias_cobro' => $v90 > 0 ? (int) round($porCobrar / $v90 * 90) : null, 'dias_pago' => $c90 > 0 ? (int) round($porPagar / $c90 * 90) : null, 'por_cobrar' => round($porCobrar, 2), 'vencido' => round($vencido, 2), 'por_pagar' => round($porPagar, 2),
                'disponible' => $disponible, 'liquidez' => $porPagar > 0 ? round(($disponible + $porCobrar) / $porPagar, 2) : null, 'capital_trabajo' => round($disponible + $porCobrar - $porPagar, 2),
                'iva_mes' => round($act['iva_debito'] - $act['iva_credito'], 2), 'iva_12' => $ivaPagado12, 'presion_iva' => $ventas12 > 0 ? round($ivaPagado12 / $ventas12 * 100, 1) : null, 'gastos_sobre_ventas' => $ventas12 > 0 ? round($gastos12 / $ventas12 * 100, 1) : null],
            'salud' => $salud];
    }

    private function mes(int $bid, Carbon $ini): array
    {
        $d = $ini->toDateString(); $h = $ini->copy()->endOfMonth()->toDateString();
        $v = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->fiscales()->whereBetween('fecha', [$d, $h]);
        $c = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$d, $h]);
        $neto = fn($q, $col) => round((float) $q->clone()->whereIn('tipo', self::VENTA)->sum($col) - (float) $q->clone()->whereIn('tipo', self::NC)->sum($col), 2);
        return ['mes' => $ini->format('Y-m'), 'label' => ucfirst($ini->locale('es')->isoFormat('MMM YY')), 'ventas_neto' => $neto($v, 'neto'), 'ventas' => $neto($v, 'total'), 'iva_debito' => $neto($v, 'iva'),
            'compras_neto' => round((float) $c->clone()->whereNotIn('tipo', self::NC)->sum('neto') - (float) $c->clone()->whereIn('tipo', self::NC)->sum('neto'), 2), 'iva_credito' => round((float) $c->clone()->whereNotIn('tipo', self::NC)->sum('iva') - (float) $c->clone()->whereIn('tipo', self::NC)->sum('iva'), 2),
            'gastos' => round((float) MovimientoFondos::withoutGlobalScopes()->where('business_id', $bid)->where('origen', 'gasto')->whereBetween('fecha', [$d, $h])->sum('egreso'), 2),
            'cobrado' => round((float) DB::table('cobros')->where('business_id', $bid)->where('estado', '!=', 'anulado')->whereBetween('fecha', [$d, $h])->sum('total'), 2)];
    }
}
