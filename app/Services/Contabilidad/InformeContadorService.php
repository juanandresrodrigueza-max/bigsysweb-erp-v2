<?php

namespace App\Services\Contabilidad;

use App\Models\Business;
use App\Models\Cheque;
use App\Models\Comprobante;
use App\Models\CuentaFondos;
use App\Models\Liquidacion;
use App\Models\MovimientoFondos;
use App\Support\Ia;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 66 · El informe del mes para el contador, en castellano: qué pasó (ventas, compras, IVA, gastos, sueldos, cobranza, plata),
// qué cambió contra el mes anterior y qué conviene revisar antes de liquidar (comprobantes sin CAE, sin asiento, anulados, cheques).
// Con clave de IA lo redacta; sin clave, lo arma con reglas. Va en "Enviar a la empresa" y lo responde Bigcito.
class InformeContadorService
{
    private const VENTA = ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC'];
    private const NC = ['NCA', 'NCB', 'NCC'];

    private function ventas(int $bid, string $desde, string $hasta) { return Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->where('sin_arca', false)->whereBetween('fecha', [$desde, $hasta]); }
    private function compras(int $bid, string $desde, string $hasta) { return Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta]); }

    private function mes(int $bid, Carbon $ini): array
    {
        $desde = $ini->toDateString(); $hasta = $ini->copy()->endOfMonth()->toDateString();
        $v = $this->ventas($bid, $desde, $hasta); $c = $this->compras($bid, $desde, $hasta);
        $neto = fn($q, $cols) => (float) $q->clone()->whereIn('tipo', self::VENTA)->sum($cols) - (float) $q->clone()->whereIn('tipo', self::NC)->sum($cols);
        return ['ventas' => round($neto($v, 'total'), 2), 'ventas_n' => $v->clone()->whereIn('tipo', array_merge(self::VENTA, self::NC))->count(), 'ventas_neto' => round($neto($v, 'neto'), 2), 'nc' => round((float) $v->clone()->whereIn('tipo', self::NC)->sum('total'), 2), 'nc_n' => $v->clone()->whereIn('tipo', self::NC)->count(),
            'compras' => round((float) $c->clone()->sum('total'), 2), 'compras_n' => $c->clone()->count(), 'iva_debito' => round($neto($v, 'iva'), 2), 'iva_credito' => round((float) $c->clone()->sum('iva'), 2),
            'percepciones_compras' => round((float) $c->clone()->sum('percepciones'), 2), 'percepciones_ventas' => round((float) $v->clone()->sum('percepciones'), 2),
            'gastos' => round((float) MovimientoFondos::withoutGlobalScopes()->where('business_id', $bid)->where('origen', 'gasto')->whereBetween('fecha', [$desde, $hasta])->sum('egreso'), 2),
            'cobrado' => round((float) DB::table('cobros')->where('business_id', $bid)->where('estado', '!=', 'anulado')->whereBetween('fecha', [$desde, $hasta])->sum('total'), 2)];
    }

    public function datos(Business $b, string $mes): array
    {
        $bid = $b->id; $ini = Carbon::parse($mes . '-01')->startOfMonth(); $fin = $ini->copy()->endOfMonth(); $desde = $ini->toDateString(); $hasta = $fin->toDateString();
        $act = $this->mes($bid, $ini); $ant = $this->mes($bid, $ini->copy()->subMonth());
        $var = fn($a, $p) => $p > 0 ? round(($a - $p) / $p * 100) : null;
        $gastosCat = MovimientoFondos::withoutGlobalScopes()->where('movimientos_fondos.business_id', $bid)->where('origen', 'gasto')->whereBetween('fecha', [$desde, $hasta])->leftJoin('expense_categories', 'expense_categories.id', '=', 'movimientos_fondos.expense_category_id')
            ->selectRaw("COALESCE(expense_categories.name, 'Sin categoría') cat, SUM(egreso) t")->groupBy('expense_categories.name')->orderByDesc('t')->limit(6)->get()->map(fn($x) => ['categoria' => $x->cat, 'monto' => round((float) $x->t, 2)])->all();
        $sueldos = Liquidacion::withoutGlobalScopes()->where('business_id', $bid)->where('periodo', $mes)->get();
        $vencido = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->where('saldo', '>', 0.005)->where('fecha_vto', '<', $hasta)->sum('saldo');
        $porPagar = (float) Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'compra')->where('estado', 'emitido')->where('saldo', '>', 0.005)->sum('saldo');
        $cuentas = CuentaFondos::withoutGlobalScopes()->where('business_id', $bid)->where('activa', true)->get();
        $sinAsiento = DB::table('comprobantes')->where('business_id', $bid)->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta])->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('asientos')->whereColumn('asientos.origen_id', 'comprobantes.id')->whereColumn('asientos.origen', 'comprobantes.direccion'))->count();
        $revisar = [];
        $cae = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('afip_estado', 'pendiente')->whereBetween('fecha', [$desde, $hasta])->count(); if ($cae) $revisar[] = "{$cae} comprobante(s) de venta sin CAE (ARCA no respondió): hay que reintentarlos antes de cerrar el IVA.";
        if ($sinAsiento) $revisar[] = "{$sinAsiento} comprobante(s) del mes sin asiento contable: Contable → Sincronizar.";
        $anul = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'anulado')->whereBetween('fecha', [$desde, $hasta])->count(); if ($anul) $revisar[] = "{$anul} comprobante(s) de venta anulados en el mes.";
        $manual = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->where('sin_arca', true)->whereBetween('fecha', [$desde, $hasta])->count(); if ($manual) $revisar[] = "{$manual} comprobante(s) manuales/internos (sin ARCA) que no van al IVA.";
        if ($act['compras_n'] === 0) $revisar[] = 'No hay compras cargadas en el mes: si hubo, falta cargarlas para tomar el crédito fiscal.';
        if ($ant['ventas'] > 0 && $act['ventas'] < $ant['ventas'] * 0.5) $revisar[] = 'Las ventas cayeron más de la mitad contra el mes anterior: confirmar que esté todo facturado.';
        $chq = (float) Cheque::withoutGlobalScopes()->where('business_id', $bid)->where('tipo', 'tercero')->where('estado', 'cartera')->sum('monto'); $chqProp = (float) Cheque::withoutGlobalScopes()->where('business_id', $bid)->where('tipo', 'propio')->where('estado', 'entregado')->sum('monto');
        $sinCat = (float) MovimientoFondos::withoutGlobalScopes()->where('business_id', $bid)->where('origen', 'gasto')->whereNull('expense_category_id')->whereBetween('fecha', [$desde, $hasta])->sum('egreso'); if ($sinCat > 0) $revisar[] = 'Gastos sin categoría por $ ' . number_format($sinCat, 0, ',', '.') . ': conviene clasificarlos para que el asiento caiga en la cuenta correcta.';
        return [
            'empresa' => $b->name, 'cuit' => $b->cuit, 'condicion_iva' => $b->condicion_iva, 'mes' => $mes, 'periodo' => ucfirst($ini->locale('es')->isoFormat('MMMM YYYY')), 'periodo_anterior' => ucfirst($ini->copy()->subMonth()->locale('es')->isoFormat('MMMM YYYY')),
            'actual' => $act, 'anterior' => $ant,
            'variaciones' => ['ventas' => $var($act['ventas'], $ant['ventas']), 'compras' => $var($act['compras'], $ant['compras']), 'gastos' => $var($act['gastos'], $ant['gastos']), 'cobrado' => $var($act['cobrado'], $ant['cobrado'])],
            'iva' => ['debito' => $act['iva_debito'], 'credito' => $act['iva_credito'], 'saldo' => round($act['iva_debito'] - $act['iva_credito'], 2), 'percepciones_sufridas' => $act['percepciones_compras'], 'percepciones_practicadas' => $act['percepciones_ventas']],
            'gastos_por_categoria' => $gastosCat,
            'sueldos' => $sueldos->isEmpty() ? null : ['liquidaciones' => $sueldos->count(), 'empleados' => $sueldos->sum(fn($l) => $l->items()->count()), 'bruto' => round((float) $sueldos->sum('total_bruto'), 2), 'neto' => round((float) $sueldos->sum('total_neto'), 2), 'contribuciones' => round((float) $sueldos->sum('total_contribuciones'), 2), 'estado' => $sueldos->pluck('estado')->unique()->implode(', ')],
            'cobranza' => ['vencido' => round($vencido, 2), 'por_pagar' => round($porPagar, 2)],
            'plata' => ['caja' => round((float) $cuentas->where('tipo', 'caja')->sum('saldo'), 2), 'bancos' => round((float) $cuentas->where('tipo', 'banco')->sum('saldo'), 2), 'cheques_cartera' => round($chq, 2), 'cheques_propios' => round($chqProp, 2)],
            'revisar' => $revisar,
        ];
    }

    // El informe en palabras. Cacheado por empresa y mes 6 horas (los números del mes cerrado no cambian a cada rato).
    public function redactar(Business $b, array $d): array
    {
        return Cache::remember("informe-contador:{$b->id}:{$d['mes']}:" . (Ia::tiene($b) ? 'ia' : 'b') . ':' . md5(json_encode([$d['actual'], $d['revisar'], $d['sueldos'], $d['plata']])), now()->addHours(6), function () use ($b, $d) {
            $f = fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
            $pct = fn($v) => $v === null ? '' : ' (' . ($v >= 0 ? '+' : '') . "{$v}% vs {$d['periodo_anterior']})";
            $a = $d['actual']; $iva = $d['iva'];
            $p = [];
            $p[] = "Ventas: {$f($a['ventas'])} en {$a['ventas_n']} comprobantes{$pct($d['variaciones']['ventas'])}" . ($a['nc_n'] ? ", con {$a['nc_n']} nota(s) de crédito por {$f($a['nc'])}" : '') . '.';
            $p[] = "Compras: {$f($a['compras'])} en {$a['compras_n']} comprobantes{$pct($d['variaciones']['compras'])}.";
            $p[] = "IVA: débito {$f($iva['debito'])}, crédito {$f($iva['credito'])} → " . ($iva['saldo'] > 0 ? "a pagar {$f($iva['saldo'])}" : 'saldo técnico a favor ' . $f(abs($iva['saldo']))) . ($iva['percepciones_sufridas'] > 0 ? "; percepciones sufridas en compras {$f($iva['percepciones_sufridas'])}" : '') . '.';
            if ($a['gastos'] > 0) $p[] = "Gastos pagados: {$f($a['gastos'])}{$pct($d['variaciones']['gastos'])}" . ($d['gastos_por_categoria'] ? '; los más grandes: ' . collect($d['gastos_por_categoria'])->take(3)->map(fn($g) => lcfirst($g['categoria']) . ' ' . $f($g['monto']))->implode(', ') : '') . '.';
            if ($d['sueldos']) $p[] = "Sueldos: {$d['sueldos']['empleados']} recibo(s), bruto {$f($d['sueldos']['bruto'])}, neto {$f($d['sueldos']['neto'])}, contribuciones {$f($d['sueldos']['contribuciones'])} ({$d['sueldos']['estado']}).";
            $p[] = "Cobranza: se cobraron {$f($a['cobrado'])}{$pct($d['variaciones']['cobrado'])}; vencido por cobrar {$f($d['cobranza']['vencido'])}; por pagar a proveedores {$f($d['cobranza']['por_pagar'])}.";
            $p[] = "Plata al cierre: caja {$f($d['plata']['caja'])}, bancos {$f($d['plata']['bancos'])}" . ($d['plata']['cheques_cartera'] > 0 ? ", cheques en cartera {$f($d['plata']['cheques_cartera'])}" : '') . ($d['plata']['cheques_propios'] > 0 ? ", cheques propios por vencer {$f($d['plata']['cheques_propios'])}" : '') . '.';
            $basico = "Informe de {$d['empresa']} · {$d['periodo']}\n\n" . implode("\n", $p) . "\n\n" . ($d['revisar'] ? "Para revisar antes de liquidar:\n- " . implode("\n- ", $d['revisar']) : 'No hay nada pendiente de revisar: el mes está completo.');
            if (! Ia::tiene($b)) return ['texto' => $basico, 'modo' => 'basico'];
            Ia::aplicar($b);
            try {
                $r = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 900,
                    'system' => "Sos el contador de confianza que le escribe al dueño de {$d['empresa']} (PyME argentina) el informe de {$d['periodo']}. Con los datos que te paso escribí un informe corto en español rioplatense, claro y sin tecnicismos innecesarios: 1) cómo fue el mes y qué cambió contra el anterior (lo importante, no todo), 2) la posición de IVA explicada en una línea, 3) gastos, sueldos y plata, 4) 'Qué conviene revisar' con los puntos pendientes tal cual te los paso (si no hay, decilo). Máximo 220 palabras, en párrafos cortos, sin saludos ni firma, sin inventar ni redondear mal los importes.",
                    'messages' => [['role' => 'user', 'content' => json_encode(array_intersect_key($d, array_flip(['periodo', 'periodo_anterior', 'actual', 'anterior', 'variaciones', 'iva', 'gastos_por_categoria', 'sueldos', 'cobranza', 'plata', 'revisar'])), JSON_UNESCAPED_UNICODE)]]]);
                $t = trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
                return $t !== '' ? ['texto' => $t, 'modo' => 'ia'] : ['texto' => $basico, 'modo' => 'basico'];
            } catch (\Throwable $e) { Log::warning('Informe contador IA: ' . $e->getMessage()); return ['texto' => $basico, 'modo' => 'basico']; }
        });
    }

    public function informe(Business $b, string $mes): array { $d = $this->datos($b, $mes); return ['datos' => $d] + $this->redactar($b, $d); }
}
