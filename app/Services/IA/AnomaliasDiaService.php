<?php

namespace App\Services\IA;

use App\Models\Business;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\MovimientoFondos;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TurnoCaja;
use App\Support\Ia;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 64 · Lo raro del día: el analista diario compara un día con los mismos días de semana de las 8 semanas anteriores (la medida
// propia de cada negocio) y cuenta sólo lo que se salió de lo normal: ventas, tickets, margen, descuentos, anulaciones, cobros, gastos,
// caja, stock y precios. Va al parte de la mañana, a Estadísticas → Analista y a Bigcito.
class AnomaliasDiaService
{
    public const SEMANAS = 8;
    private const TIPOS_VENTA = ['FA', 'FB', 'FC', 'FE', 'FX'];

    private function ventas(int $bid) { return Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', self::TIPOS_VENTA); }
    private static function pesos(float $n): string { return '$ ' . number_format($n, 0, ',', '.'); }

    // Los mismos días de semana de las semanas anteriores (sólo los que tuvieron ventas: un feriado no es "lo normal").
    private function fechasBase(int $bid, Carbon $dia): array
    {
        $f = []; for ($i = 1; $i <= self::SEMANAS; $i++) $f[] = $dia->copy()->subWeeks($i)->toDateString();
        $con = $this->ventas($bid)->whereIn('fecha', $f)->selectRaw('fecha, COUNT(*) n')->groupBy('fecha')->pluck('n', 'fecha')->keys()->map(fn($x) => Carbon::parse($x)->toDateString())->all();
        return array_values(array_intersect($f, $con));
    }

    // Media y desvío de una serie; z-score del valor del día.
    private static function stats(array $serie): array
    {
        $n = count($serie); if ($n === 0) return ['media' => 0.0, 'desvio' => 0.0, 'n' => 0];
        $m = array_sum($serie) / $n; $v = $n > 1 ? array_sum(array_map(fn($x) => ($x - $m) ** 2, $serie)) / ($n - 1) : 0.0;
        return ['media' => $m, 'desvio' => sqrt($v), 'n' => $n];
    }

    // ¿Se salió de lo normal? Hace falta base suficiente (3 días), un desvío grande (z ≥ 2) y una diferencia que importe (≥ 30%).
    private static function raro(float $valor, array $st, float $minPct = 30.0): ?array
    {
        if ($st['n'] < 3 || $st['media'] <= 0) return null;
        $pct = ($valor - $st['media']) / $st['media'] * 100;
        $z = $st['desvio'] > 0 ? ($valor - $st['media']) / $st['desvio'] : (abs($pct) >= $minPct ? 3.0 : 0.0);
        return abs($z) >= 2 && abs($pct) >= $minPct ? ['pct' => round($pct), 'z' => round($z, 1)] : null;
    }

    public function anomalias(Business $b, ?Carbon $dia = null): array
    {
        $dia = ($dia ?? today()->subDay())->copy()->startOfDay(); $bid = $b->id; $d = $dia->toDateString();
        return Cache::remember("anomalias:{$bid}:{$d}", $dia->isToday() ? now()->addMinutes(30) : now()->addHours(12), function () use ($b, $bid, $dia, $d) {
            $base = $this->fechasBase($bid, $dia); $out = []; $fecha = $dia->format('d/m');
            $serie = fn($q, string $expr) => $q->whereIn('fecha', $base)->selectRaw("fecha, {$expr} v")->groupBy('fecha')->pluck('v')->map(fn($x) => (float) $x)->all();
            $deDia = fn($q, string $expr) => (float) $q->whereDate('fecha', $d)->selectRaw("{$expr} v")->value('v');

            // 1. Ventas del día y cantidad de tickets
            $vt = $deDia($this->ventas($bid), 'COALESCE(SUM(total),0)'); $st = self::stats($serie($this->ventas($bid), 'SUM(total)'));
            if ($r = self::raro($vt, $st)) $out[] = ['cat' => 'ventas', 'sev' => $r['pct'] < 0 ? 'critica' : 'info', 'titulo' => ($r['pct'] < 0 ? 'Se vendió ' . abs($r['pct']) . '% menos' : "Se vendió {$r['pct']}% más") . " que un {$dia->locale('es')->dayName} normal", 'detalle' => self::pesos($vt) . ' contra ' . self::pesos($st['media']) . " de promedio en los últimos {$st['n']} " . $dia->locale('es')->dayName . 's.', 'valor' => $vt, 'esperado' => round($st['media']), 'desvio_pct' => $r['pct'], 'url' => '/estadisticas/ventas'];
            $nt = $deDia($this->ventas($bid), 'COUNT(*)'); $stn = self::stats($serie($this->ventas($bid), 'COUNT(*)'));
            if (($r = self::raro($nt, $stn)) && ! ($out && $out[0]['cat'] === 'ventas' && ($r['pct'] < 0) === ($out[0]['desvio_pct'] < 0))) $out[] = ['cat' => 'ventas', 'sev' => 'aviso', 'titulo' => ($r['pct'] < 0 ? abs($r['pct']) . '% menos tickets' : "{$r['pct']}% más tickets") . ' que lo normal', 'detalle' => "{$nt} comprobantes contra " . round($stn['media']) . ' de promedio.', 'valor' => $nt, 'esperado' => round($stn['media']), 'desvio_pct' => $r['pct'], 'url' => '/estadisticas/ventas'];
            if ($nt >= 3 && $stn['media'] > 0 && $st['media'] > 0) { $tp = $vt / $nt; $tpb = $st['media'] / $stn['media']; $pct = ($tp - $tpb) / $tpb * 100; if (abs($pct) >= 40) $out[] = ['cat' => 'ventas', 'sev' => 'info', 'titulo' => 'Ticket promedio ' . ($pct < 0 ? round(abs($pct)) . '% más bajo' : round($pct) . '% más alto') . ' que lo normal', 'detalle' => self::pesos($tp) . ' por comprobante contra ' . self::pesos($tpb) . '.', 'valor' => round($tp), 'esperado' => round($tpb), 'desvio_pct' => round($pct), 'url' => '/estadisticas/ventas']; }

            // 2. Margen del día (con el costo guardado en cada renglón)
            $itemsDia = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $bid)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', self::TIPOS_VENTA);
            $mg = fn($fechas) => (clone $itemsDia)->whereIn('comprobantes.fecha', (array) $fechas)->whereNotNull('comprobante_items.costo_unit')->selectRaw('COALESCE(SUM(comprobante_items.neto),0) neto, COALESCE(SUM(comprobante_items.cantidad * comprobante_items.costo_unit),0) costo')->first();
            $m = $mg([$d]); $mb = $mg($base);
            if ((float) $m->neto > 0 && (float) $mb->neto > 0) { $pm = ((float) $m->neto - (float) $m->costo) / (float) $m->neto * 100; $pb = ((float) $mb->neto - (float) $mb->costo) / (float) $mb->neto * 100; if ($pb - $pm >= 8) $out[] = ['cat' => 'margen', 'sev' => $pm < 0 ? 'critica' : 'aviso', 'titulo' => 'El margen del día fue ' . round($pm) . '% (normal: ' . round($pb) . '%)', 'detalle' => 'Puede ser descuento de más, precio viejo o costo que subió. Revisá Rentabilidad.', 'valor' => round($pm, 1), 'esperado' => round($pb, 1), 'desvio_pct' => round($pm - $pb), 'url' => '/estadisticas/rentabilidad']; }

            // 3. Descuentos: renglones con descuento y plata regalada
            $dsc = fn($fechas) => (clone $itemsDia)->whereIn('comprobantes.fecha', (array) $fechas)->where('comprobante_items.descuento', '>', 0)->selectRaw('COUNT(*) n, COALESCE(SUM(comprobante_items.cantidad * comprobante_items.precio_unit * comprobante_items.descuento / 100),0) plata')->first();
            $dd = $dsc([$d]); $db = $dsc($base);
            if ($base && (float) $dd->plata > 0) { $prom = (float) $db->plata / count($base); if ($prom > 0 && (float) $dd->plata >= $prom * 2.5 && (float) $dd->plata >= 5000) $out[] = ['cat' => 'control', 'sev' => 'aviso', 'titulo' => 'Se descontó ' . self::pesos((float) $dd->plata) . ' en el día (normal: ' . self::pesos($prom) . ')', 'detalle' => "{$dd->n} renglones con descuento. Mirá quién los hizo en el control del día.", 'valor' => round((float) $dd->plata), 'esperado' => round($prom), 'desvio_pct' => round(((float) $dd->plata - $prom) / $prom * 100), 'url' => '/comprobantes?fecha=' . $d]; }

            // 4. Anulaciones del día
            $anul = Comprobante::withoutGlobalScopes()->where('business_id', $bid)->where('direccion', 'venta')->where('estado', 'anulado')->whereDate('updated_at', $d)->count();
            if ($anul >= 3 && $nt > 0 && $anul / max(1, $nt + $anul) >= 0.15) $out[] = ['cat' => 'control', 'sev' => 'aviso', 'titulo' => "{$anul} comprobantes anulados en el día", 'detalle' => 'Es ' . round($anul / ($nt + $anul) * 100) . '% de lo emitido. Mucho para un día: revisá si hubo errores de carga o algo raro.', 'valor' => $anul, 'esperado' => 0, 'desvio_pct' => null, 'url' => '/comprobantes?estado=anulado'];

            // 5. Cobros y gastos del día contra lo normal
            $cob = fn($fechas) => Cobro::withoutGlobalScopes()->where('business_id', $bid)->where('estado', '!=', 'anulado')->whereIn('fecha', (array) $fechas);
            $cv = (float) $cob([$d])->sum('total'); $cs = self::stats($cob($base)->selectRaw('fecha, SUM(total) v')->groupBy('fecha')->pluck('v')->map(fn($x) => (float) $x)->all());
            if ($cs['n'] >= 3 && ($r = self::raro($cv, $cs, 40))) $out[] = ['cat' => 'cobranza', 'sev' => $r['pct'] < 0 ? 'aviso' : 'info', 'titulo' => 'Se cobró ' . ($r['pct'] < 0 ? abs($r['pct']) . '% menos' : "{$r['pct']}% más") . ' que lo normal', 'detalle' => self::pesos($cv) . ' contra ' . self::pesos($cs['media']) . ' de promedio.', 'valor' => $cv, 'esperado' => round($cs['media']), 'desvio_pct' => $r['pct'], 'url' => '/clientes/cobranzas'];
            $gas = fn($fechas) => MovimientoFondos::withoutGlobalScopes()->where('business_id', $bid)->where('origen', 'gasto')->whereIn('fecha', (array) $fechas);
            $gv = (float) $gas([$d])->sum('egreso'); $gs = self::stats($gas($base)->selectRaw('fecha, SUM(egreso) v')->groupBy('fecha')->pluck('v')->map(fn($x) => (float) $x)->all());
            if ($gs['n'] >= 3 && $gv > $gs['media'] && ($r = self::raro($gv, $gs, 50))) { $top = $gas([$d])->orderByDesc('egreso')->first(); $out[] = ['cat' => 'gastos', 'sev' => 'aviso', 'titulo' => "Gastos del día {$r['pct']}% arriba de lo normal", 'detalle' => self::pesos($gv) . ' contra ' . self::pesos($gs['media']) . ($top ? ". El más grande: {$top->concepto} (" . self::pesos((float) $top->egreso) . ')' : '.'), 'valor' => $gv, 'esperado' => round($gs['media']), 'desvio_pct' => $r['pct'], 'url' => '/fondos']; }

            // 6. Caja: cierres con diferencia ese día
            $difs = TurnoCaja::withoutGlobalScopes()->where('business_id', $bid)->whereNotNull('cierre')->whereDate('cierre', $d)->where(fn($q) => $q->where('diferencia', '<=', -1000)->orWhere('diferencia', '>=', 1000))->with('user:id,name')->get();
            foreach ($difs as $t) $out[] = ['cat' => 'caja', 'sev' => (float) $t->diferencia <= -5000 ? 'critica' : 'aviso', 'titulo' => 'Caja de ' . ($t->user?->name ?? 'un cajero') . ' cerró con ' . ((float) $t->diferencia < 0 ? 'faltante' : 'sobrante') . ' de ' . self::pesos(abs((float) $t->diferencia)), 'detalle' => 'Esperado ' . self::pesos((float) $t->saldo_esperado) . ', contado ' . self::pesos((float) $t->saldo_contado) . '.', 'valor' => (float) $t->diferencia, 'esperado' => 0, 'desvio_pct' => null, 'url' => '/fondos/cierres'];

            // 7. Stock: ajustes negativos grandes en el día
            $aj = StockMovement::withoutGlobalScopes()->where('business_id', $bid)->where('type', 'ajuste')->where('quantity', '<', 0)->whereDate('created_at', $d)->with('product:id,name,cost')->get();
            $plataAj = (float) $aj->sum(fn($x) => abs((float) $x->quantity) * (float) (($x->costo_unit ?: $x->product?->cost) ?? 0));
            if ($aj->count() >= 3 || $plataAj >= 20000) $out[] = ['cat' => 'stock', 'sev' => $plataAj >= 100000 ? 'critica' : 'aviso', 'titulo' => $aj->count() . ' ajustes de stock para abajo por ' . self::pesos($plataAj), 'detalle' => 'Mercadería que se dio de baja a mano: ' . $aj->take(3)->map(fn($x) => ($x->product?->name ?? 'artículo') . ' ' . rtrim(rtrim(number_format(abs((float) $x->quantity), 2, ',', '.'), '0'), ','))->implode(', ') . ($aj->count() > 3 ? '…' : '') . '. ¿Rotura, vencimiento o faltante?', 'valor' => $plataAj, 'esperado' => 0, 'desvio_pct' => null, 'url' => '/stock/movimientos?tipo=ajuste'];

            // 8. Artículos que volaron: unidades del día ≥ 4 veces su promedio diario (riesgo de quedarse sin)
            if ($base) {
                $unid = fn($fechas) => (clone $itemsDia)->whereIn('comprobantes.fecha', (array) $fechas)->whereNotNull('comprobante_items.product_id')->selectRaw('comprobante_items.product_id, SUM(comprobante_items.cantidad) u')->groupBy('comprobante_items.product_id')->pluck('u', 'comprobante_items.product_id');
                $ud = $unid([$d]); $ub = $unid($base); $nb = count($base);
                $voladores = $ud->filter(fn($u, $pid) => (float) $u >= 10 && isset($ub[$pid]) && (float) $u >= 4 * ((float) $ub[$pid] / $nb))->sortDesc()->take(3);
                foreach ($voladores as $pid => $u) { $p = Product::withoutGlobalScopes()->find($pid); if (! $p) continue; $prom = (float) $ub[$pid] / $nb; $out[] = ['cat' => 'stock', 'sev' => $p->controla_stock && (float) $p->stock <= (float) $p->stock_min ? 'aviso' : 'info', 'titulo' => "{$p->name} voló: " . rtrim(rtrim(number_format((float) $u, 2, ',', '.'), '0'), ',') . ' unidades (normal: ' . round($prom, 1) . ')', 'detalle' => 'Quedan ' . rtrim(rtrim(number_format((float) $p->stock, 2, ',', '.'), '0'), ',') . ($p->controla_stock && (float) $p->stock <= (float) $p->stock_min ? ', bajo el mínimo: reponé' : '') . '.', 'valor' => (float) $u, 'esperado' => round($prom, 1), 'desvio_pct' => $prom > 0 ? round(((float) $u - $prom) / $prom * 100) : null, 'url' => "/stock?buscar=" . urlencode((string) $p->sku)]; }
            }

            // 9. Vendido muy por debajo de la lista
            $baratos = (clone $itemsDia)->join('products', 'products.id', '=', 'comprobante_items.product_id')->where('comprobantes.fecha', $d)->where('products.price', '>', 0)->whereRaw('comprobante_items.precio_unit < products.price * 0.7')->selectRaw('products.name, comprobante_items.precio_unit, products.price, comprobantes.id cid')->limit(3)->get();
            if ($baratos->count()) $out[] = ['cat' => 'control', 'sev' => 'aviso', 'titulo' => $baratos->count() . ' renglón(es) vendidos más de 30% abajo de la lista', 'detalle' => $baratos->map(fn($x) => "{$x->name} a " . self::pesos((float) $x->precio_unit) . ' (lista ' . self::pesos((float) $x->price) . ')')->implode('; ') . '.', 'valor' => $baratos->count(), 'esperado' => 0, 'desvio_pct' => null, 'url' => "/comprobantes/{$baratos->first()->cid}"];

            $orden = ['critica' => 0, 'aviso' => 1, 'info' => 2];
            usort($out, fn($x, $y) => $orden[$x['sev']] <=> $orden[$y['sev']]);
            return ['fecha' => $d, 'dia' => $dia->locale('es')->isoFormat('dddd D/M'), 'base_dias' => count($base), 'anomalias' => $out];
        });
    }

    // En palabras: con clave, un párrafo de la IA en voz de analista; sin clave, lo mismo armado con reglas.
    public function texto(Business $b, array $r): array
    {
        $a = $r['anomalias'];
        if (! $a) return ['texto' => $r['base_dias'] < 3 ? 'Todavía no hay suficientes ' . explode(' ', $r['dia'])[0] . 's en la historia para decir qué es normal; en un par de semanas empiezo a comparar.' : "Nada raro el {$r['dia']}: ventas, cobros, caja y stock dentro de lo normal para ese día de la semana.", 'modo' => 'basico'];
        $lineas = collect($a)->map(fn($x) => "- [{$x['sev']}] {$x['titulo']}. {$x['detalle']}")->implode("\n");
        if (Ia::tiene($b)) {
            Ia::aplicar($b);
            try {
                $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(25)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 350,
                    'system' => "Sos el analista de {$b->name}, una PyME argentina. Te paso lo que se salió de lo normal el {$r['dia']} (comparado con los mismos días de semana anteriores). Escribí un solo párrafo corto (máximo 90 palabras), en español rioplatense, directo, como se lo contarías al dueño en la puerta: qué pasó, qué puede explicarlo y qué conviene mirar hoy. Sin títulos ni listas.",
                    'messages' => [['role' => 'user', 'content' => $lineas]]]);
                $t = trim(collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
                if ($t !== '') return ['texto' => $t, 'modo' => 'ia'];
            } catch (\Throwable $e) { Log::warning('Anomalías IA: ' . $e->getMessage()); }
        }
        $crit = array_filter($a, fn($x) => $x['sev'] === 'critica'); $resto = array_filter($a, fn($x) => $x['sev'] !== 'critica');
        $t = "Lo raro del {$r['dia']}: " . ($crit ? implode('; ', array_map(fn($x) => lcfirst($x['titulo']), array_slice($crit, 0, 3))) . '. ' : '') . ($resto ? ($crit ? 'Además, ' : '') . implode('; ', array_map(fn($x) => lcfirst($x['titulo']), array_slice($resto, 0, 3))) . '.' : '');
        return ['texto' => trim($t), 'modo' => 'basico'];
    }

    // Para el parte de la mañana: hasta 3 renglones, o nada si no hubo nada raro.
    public function paraElParte(Business $b, Carbon $dia): ?string
    {
        try { $r = $this->anomalias($b, $dia); } catch (\Throwable $e) { Log::warning('Anomalías parte: ' . $e->getMessage()); return null; }
        if (! $r['anomalias']) return null;
        return "🔎 *Lo raro de ayer*\n" . collect($r['anomalias'])->take(3)->map(fn($x) => ($x['sev'] === 'critica' ? '🔴 ' : '• ') . $x['titulo'])->implode("\n");
    }
}
