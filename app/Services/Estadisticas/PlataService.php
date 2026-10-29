<?php

namespace App\Services\Estadisticas;

use App\Models\Business;
use App\Models\Cheque;
use App\Models\Cobro;
use App\Models\CobroMedio;
use App\Models\Comprobante;
use App\Support\Sql;
use Illuminate\Support\Carbon;

// Fase 33.5 · Plata: qué entra y cuándo (facturas y cheques por tramo), la cartera por semana de depósito, la cobranza por cobrador
// y el IVA del mes con percepciones y retenciones.
class PlataService
{
    public const COMO = [
        'entra' => 'Lo que te deben (saldo de facturas y notas de débito de venta, sin anuladas) más los cheques de terceros en cartera, por cuándo vence: ya vencido, hasta 30 días, de 31 a 60, de 61 a 90 y más de 90. Las cuentas de contado (consumidor final) no entran.',
        'cartera' => 'Los cheques de terceros en cartera, agrupados por la semana en que se pueden depositar.',
        'cobradores' => 'Los recibos del período por quien cobró (el cobrador del recibo; si no tiene, el del cliente), con cuánto y cuántos.',
        'iva' => 'Débito fiscal: el IVA de las ventas del mes (facturas y notas de débito menos notas de crédito). Crédito fiscal: el IVA de las compras. Las percepciones de IVA sufridas en compras y las retenciones de IVA que te hicieron al cobrar restan. Es una cuenta sobre lo cargado, no una declaración jurada.',
    ];

    public function queEntra(Business $b): array
    {
        $hoy = today(); $tramos = ['vencido' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'mas90' => 0.0];
        $cf = \App\Models\Contact::where('name', Sql::like(), '%consumidor final%')->pluck('id')->all();
        $facturas = Comprobante::ventas()->pendientesCobro()->whereNotIn('contact_id', $cf ?: [0])->get(['id', 'contact_id', 'fecha', 'fecha_vto', 'saldo', 'tipo']);
        $cheques = Cheque::enCartera()->get(['id', 'monto', 'fecha_pago', 'emisor', 'banco', 'numero']);
        $tramo = function (?Carbon $vto) use ($hoy) { if (! $vto || $vto->lt($hoy)) return 'vencido'; $d = $hoy->diffInDays($vto); return $d <= 30 ? 'd30' : ($d <= 60 ? 'd60' : ($d <= 90 ? 'd90' : 'mas90')); };
        $fac = $tramos; $che = $tramos;
        foreach ($facturas as $f) $fac[$tramo($f->fecha_vto ?: $f->fecha)] += (float) $f->saldo;
        foreach ($cheques as $c) $che[$tramo($c->fecha_pago)] += (float) $c->monto;
        $total = array_map(fn($k) => round($fac[$k] + $che[$k], 2), array_combine(array_keys($tramos), array_keys($tramos)));
        return ['tramos' => $total, 'facturas' => array_map(fn($v) => round($v, 2), $fac), 'cheques' => array_map(fn($v) => round($v, 2), $che), 'total' => round(array_sum($total), 2), 'cantidad_facturas' => $facturas->count(), 'cantidad_cheques' => $cheques->count(), 'como' => self::COMO['entra']];
    }

    public function carteraPorSemana(Business $b): array
    {
        $hoy = today()->startOfWeek();
        $semanas = Cheque::enCartera()->orderBy('fecha_pago')->get()->groupBy(fn($c) => $c->fecha_pago->lt($hoy) ? 'vencidos' : $c->fecha_pago->copy()->startOfWeek()->toDateString())
            ->map(fn($g, $k) => ['semana' => $k, 'desde' => $k === 'vencidos' ? null : $k, 'hasta' => $k === 'vencidos' ? null : Carbon::parse($k)->endOfWeek()->toDateString(), 'monto' => round($g->sum('monto'), 2), 'cantidad' => $g->count(), 'cheques' => $g->map(fn($c) => ['id' => $c->id, 'numero' => $c->numero, 'banco' => $c->banco, 'emisor' => $c->emisor, 'monto' => (float) $c->monto, 'fecha_pago' => $c->fecha_pago->toDateString()])->values()->all()])->values();
        return ['semanas' => $semanas->all(), 'total' => round($semanas->sum('monto'), 2), 'como' => self::COMO['cartera']];
    }

    public function cobranzaPorCobrador(Business $b, Carbon $desde, Carbon $hasta): array
    {
        $rows = Cobro::where('estado', '!=', 'anulado')->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->leftJoin('vendedores', 'vendedores.id', '=', 'cobros.cobrador_id')
            ->selectRaw("COALESCE(vendedores.nombre, 'Sin cobrador') as nombre, COALESCE(cobros.cobrador_id, 0) as cid, SUM(cobros.total) as monto, COUNT(*) as n, COUNT(DISTINCT cobros.contact_id) as clientes")->groupByRaw("COALESCE(vendedores.nombre, 'Sin cobrador'), COALESCE(cobros.cobrador_id, 0)")->orderByDesc('monto')->get();
        $total = (float) $rows->sum('monto');
        return ['items' => $rows->map(fn($r) => ['nombre' => $r->nombre, 'cobrador_id' => (int) $r->cid ?: null, 'monto' => (float) $r->monto, 'recibos' => (int) $r->n, 'clientes' => (int) $r->clientes, 'parte' => $total > 0 ? round((float) $r->monto / $total * 100, 1) : 0])->values()->all(), 'total' => round($total, 2), 'como' => self::COMO['cobradores']];
    }

    public function ivaDelMes(Business $b, ?Carbon $mes = null): array
    {
        $mes ??= today(); $d = $mes->copy()->startOfMonth()->toDateString(); $h = $mes->copy()->endOfMonth()->toDateString();
        $signo = "CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -1 ELSE 1 END";
        $q = fn(string $dir) => Comprobante::where('direccion', $dir)->emitidos()->whereIn('tipo', TableroService::CON_NC)->whereBetween('fecha', [$d, $h]);
        $debito = (float) $q('venta')->selectRaw("COALESCE(SUM(({$signo}) * iva),0) s")->value('s');
        $credito = (float) $q('compra')->selectRaw("COALESCE(SUM(({$signo}) * iva),0) s")->value('s');
        $ventasNeto = (float) $q('venta')->selectRaw("COALESCE(SUM(({$signo}) * (neto - descuento)),0) s")->value('s');
        $comprasNeto = (float) $q('compra')->selectRaw("COALESCE(SUM(({$signo}) * (neto - descuento)),0) s")->value('s');
        // percepciones de IVA sufridas en compras (impuestos tipo iva / percepcion_iva) y retenciones de IVA al cobrar
        $percIva = (float) \App\Models\ComprobanteImpuesto::join('comprobantes', 'comprobantes.id', '=', 'comprobante_impuestos.comprobante_id')->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')->whereBetween('comprobantes.fecha', [$d, $h])
            ->where(fn($w) => $w->where('comprobante_impuestos.tipo', 'iva')->orWhere('comprobante_impuestos.tipo', 'like', 'perc_iva%')->orWhere('comprobante_impuestos.tipo', 'like', 'percepcion_iva%'))->sum('comprobante_impuestos.monto');
        $retIva = (float) CobroMedio::join('cobros', 'cobros.id', '=', 'cobro_medios.cobro_id')->where('cobros.business_id', $b->id)->where('cobros.estado', '!=', 'anulado')->whereBetween('cobros.fecha', [$d, $h])->where('cobro_medios.medio', 'retencion')
            ->where(fn($w) => $w->whereNull('cobro_medios.datos')->orWhere('cobro_medios.datos', 'like', '%iva%'))->sum('cobro_medios.monto');
        $posicion = round($debito - $credito - $percIva - $retIva, 2);
        return ['mes' => $mes->locale('es')->isoFormat('MMMM YYYY'), 'debito' => round($debito, 2), 'credito' => round($credito, 2), 'percepciones' => round($percIva, 2), 'retenciones' => round($retIva, 2), 'posicion' => $posicion, 'a_pagar' => max(0, $posicion), 'a_favor' => max(0, -$posicion), 'ventas_neto' => round($ventasNeto, 2), 'compras_neto' => round($comprasNeto, 2), 'como' => self::COMO['iva']];
    }
}
