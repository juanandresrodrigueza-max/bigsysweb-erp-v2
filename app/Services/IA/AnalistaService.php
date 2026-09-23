<?php

namespace App\Services\IA;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\IndiceIpc;
use App\Models\Product;
use App\Models\TurnoCaja;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Analista de negocio: mira los datos y dice qué está pasando y qué conviene hacer. Con clave de Anthropic suma un informe en palabras.
class AnalistaService
{
    public function hallazgos(Business $b): array
    {
        $id = $b->id; $hoy = today(); $h = [];
        $ventas = fn($d, $ha) => (float) Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->whereBetween('fecha', [$d, $ha])->sum('total');
        // 1. Ventas del mes vs mes anterior (mismo tramo de días)
        $mes = $ventas($hoy->copy()->startOfMonth(), $hoy); $ant = $ventas($hoy->copy()->subMonth()->startOfMonth(), $hoy->copy()->subMonth());
        if ($ant > 0) { $var = round(($mes - $ant) / $ant * 100, 1); if ($var <= -15) $h[] = ['cat' => 'ventas', 'sev' => 'critica', 'titulo' => "Las ventas del mes van {$var}% abajo del mes pasado", 'detalle' => 'Comparando los mismos días: $ ' . number_format($mes, 0, ',', '.') . ' contra $ ' . number_format($ant, 0, ',', '.') . '. Mirá qué clientes o artículos cayeron.', 'url' => '/estadisticas', 'impacto' => round($ant - $mes)]; elseif ($var >= 15) $h[] = ['cat' => 'ventas', 'sev' => 'ok', 'titulo' => "Las ventas del mes van {$var}% arriba del mes pasado", 'detalle' => '$ ' . number_format($mes, 0, ',', '.') . ' contra $ ' . number_format($ant, 0, ',', '.') . ' en los mismos días. Asegurate de tener stock para sostenerlo.', 'url' => '/estadisticas', 'impacto' => round($mes - $ant)]; }
        // 2. Clientes que dejaron de comprar: compraban seguido y hace 60 días no aparecen
        $clientes = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->where('fecha', '>=', $hoy->copy()->subDays(240))->selectRaw('contact_id, COUNT(*) n, MAX(fecha) ultima, SUM(total) monto')->groupBy('contact_id')->having('n', '>=', 3)->get();
        $perdidos = $clientes->filter(fn($c) => \Carbon\Carbon::parse($c->ultima)->lt($hoy->copy()->subDays(60)))->sortByDesc('monto')->take(5);
        foreach ($perdidos as $c) { $cl = Contact::withoutGlobalScopes()->find($c->contact_id); if (! $cl || $cl->name === 'Consumidor Final') continue; $h[] = ['cat' => 'clientes', 'sev' => 'aviso', 'titulo' => "{$cl->name} dejó de comprar", 'detalle' => "Compró {$c->n} veces por $ " . number_format((float) $c->monto, 0, ',', '.') . ' y la última fue el ' . \Carbon\Carbon::parse($c->ultima)->format('d/m/Y') . '. Un llamado o una oferta puede recuperarlo.', 'url' => "/clientes/{$cl->id}", 'impacto' => round((float) $c->monto / 8 * 2)]; }
        // 3. Margen negativo o bajo por artículo (últimos 30 días)
        $margenes = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->join('products', 'products.id', '=', 'comprobante_items.product_id')->where('comprobantes.business_id', $id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE'])->where('comprobantes.fecha', '>=', $hoy->copy()->subDays(30))
            ->selectRaw('products.id, products.name, SUM(comprobante_items.neto) neto, SUM(comprobante_items.cantidad * products.cost) costo')->groupBy('products.id', 'products.name')->get();
        foreach ($margenes->filter(fn($m) => (float) $m->neto > 0 && ((float) $m->neto - (float) $m->costo) / (float) $m->neto < 0.05)->sortBy(fn($m) => (float) $m->neto - (float) $m->costo)->take(5) as $m) { $mg = round(((float) $m->neto - (float) $m->costo) / (float) $m->neto * 100, 1); $h[] = ['cat' => 'margen', 'sev' => $mg < 0 ? 'critica' : 'aviso', 'titulo' => "{$m->name} se vende " . ($mg < 0 ? 'por debajo del costo' : "con {$mg}% de margen"), 'detalle' => 'Neto vendido $ ' . number_format((float) $m->neto, 0, ',', '.') . ' con costo $ ' . number_format((float) $m->costo, 0, ',', '.') . ' en 30 días. Revisá el precio o el costo cargado.', 'url' => "/stock/{$m->id}", 'impacto' => round((float) $m->costo - (float) $m->neto)]; }
        // 4. Precios sin actualizar con inflación acumulada
        $ipc = IndiceIpc::orderByDesc('periodo')->limit(2)->get(); $infl = $ipc->count() === 2 ? round(((float) $ipc[0]->valor / (float) $ipc[1]->valor - 1) * 100, 1) : null;
        $viejos = Product::withoutGlobalScopes()->where('business_id', $id)->where('active', true)->whereIn('tipo', ['producto', 'elaborado'])->where(fn($q) => $q->whereNull('precio_actualizado_en')->orWhere('precio_actualizado_en', '<', $hoy->copy()->subDays(60)))->count();
        if ($viejos > 0) $h[] = ['cat' => 'precios', 'sev' => 'aviso', 'titulo' => "{$viejos} artículos con precio sin tocar hace más de 60 días", 'detalle' => ($infl !== null ? "El IPC del último mes fue {$infl}%. " : '') . 'Con inflación, vender a precio viejo es regalar margen. Usá "Actualizar precios" por rubro o proveedor.', 'url' => '/stock', 'impacto' => 0];
        // 5. Plata parada en stock sin movimiento
        $muertos = app(\App\Services\Stock\InformesStockService::class); try { \Illuminate\Support\Facades\Auth::user() || \Illuminate\Support\Facades\Auth::setUser($b->owner); $mu = $muertos->muertos(90); if ($mu['total'] > 0) $h[] = ['cat' => 'stock', 'sev' => $mu['total'] > 500000 ? 'aviso' : 'info', 'titulo' => 'Hay $ ' . number_format($mu['total'], 0, ',', '.') . ' en stock que no se movió en 90 días', 'detalle' => count($mu['filas']) . ' artículos sin ventas. Promoción, combo o devolución al proveedor.', 'url' => '/stock/informes?tipo=muertos', 'impacto' => round($mu['total'])]; } catch (\Throwable $e) {}
        // 6. Cobranza: deuda vencida y clientes con más mora
        $vencido = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->where('saldo', '>', 0.005)->whereDate('fecha_vto', '<', $hoy)->selectRaw('contact_id, SUM(saldo) s, MIN(fecha_vto) v')->groupBy('contact_id')->orderByDesc('s')->get();
        $totVenc = (float) $vencido->sum('s');
        if ($totVenc > 0) { $top = $vencido->first(); $cl = Contact::withoutGlobalScopes()->find($top->contact_id); $h[] = ['cat' => 'cobranza', 'sev' => $totVenc > $mes * 0.5 ? 'critica' : 'aviso', 'titulo' => 'Tenés $ ' . number_format($totVenc, 0, ',', '.') . ' vencidos por cobrar', 'detalle' => $vencido->count() . ' clientes. El que más debe es ' . ($cl?->name ?? '?') . ' con $ ' . number_format((float) $top->s, 0, ',', '.') . ' desde el ' . \Carbon\Carbon::parse($top->v)->format('d/m') . '. Activá los recordatorios automáticos.', 'url' => '/clientes/cobranzas', 'impacto' => round($totVenc)]; }
        // 7. Cajas con diferencias
        $difs = TurnoCaja::withoutGlobalScopes()->where('business_id', $id)->whereNotNull('cierre')->where('cierre', '>=', $hoy->copy()->subDays(30))->where(fn($q) => $q->where('diferencia', '<', -1000)->orWhere('diferencia', '>', 1000))->with('user:id,name')->get();
        if ($difs->count()) $h[] = ['cat' => 'caja', 'sev' => 'aviso', 'titulo' => $difs->count() . ' cierres de caja con diferencia en 30 días', 'detalle' => 'Total $ ' . number_format((float) $difs->sum('diferencia'), 0, ',', '.') . '. Usuarios: ' . $difs->pluck('user.name')->unique()->implode(', ') . '.', 'url' => '/fondos', 'impacto' => round(abs((float) $difs->sum('diferencia')))];
        // 8. Descuentos y precios modificados por usuario
        $mods = AuditLog::withoutGlobalScopes()->where('business_id', $id)->where('accion', 'precio_modificado')->where('created_at', '>=', $hoy->copy()->subDays(30))->with('user:id,name')->get();
        $porUser = $mods->groupBy('user_id')->map(fn($g) => ['n' => $g->count(), 'dif' => $g->sum(fn($a) => (float) ($a->despues['diferencia'] ?? 0)), 'nombre' => $g->first()->user?->name])->sortBy('dif');
        if ($porUser->count() && $porUser->first()['dif'] < -20000) { $u = $porUser->first(); $h[] = ['cat' => 'control', 'sev' => 'aviso', 'titulo' => "{$u['nombre']} facturó $ " . number_format(abs($u['dif']), 0, ',', '.') . ' por debajo de lista en 30 días', 'detalle' => "{$u['n']} precios modificados al facturar. Si es política comercial, mejor cargarlo como descuento del cliente.", 'url' => '/comprobantes/novedades', 'impacto' => round(abs($u['dif']))]; }
        // 9. Anulaciones
        $anul = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'anulado')->where('updated_at', '>=', $hoy->copy()->subDays(30))->count();
        $emit = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->whereIn('estado', ['emitido', 'anulado'])->where('fecha', '>=', $hoy->copy()->subDays(30))->count();
        if ($emit > 10 && $anul / $emit > 0.08) $h[] = ['cat' => 'control', 'sev' => 'aviso', 'titulo' => 'Se anula ' . round($anul / $emit * 100) . '% de los comprobantes', 'detalle' => "{$anul} anulados de {$emit} en 30 días. Es alto: revisá si hay errores de carga o algo raro.", 'url' => '/configuracion/auditoria', 'impacto' => 0];
        // 10. Concentración de ventas en pocos clientes
        $porCliente = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->where('fecha', '>=', $hoy->copy()->subDays(90))->selectRaw('contact_id, SUM(total) t')->groupBy('contact_id')->orderByDesc('t')->get();
        $tot90 = (float) $porCliente->sum('t');
        if ($tot90 > 0 && $porCliente->count() > 3 && (float) $porCliente->first()->t / $tot90 > 0.4) { $cl = Contact::withoutGlobalScopes()->find($porCliente->first()->contact_id); $h[] = ['cat' => 'riesgo', 'sev' => 'info', 'titulo' => ($cl?->name ?? 'Un cliente') . ' es el ' . round((float) $porCliente->first()->t / $tot90 * 100) . '% de tus ventas', 'detalle' => 'Depender tanto de un cliente es riesgoso. Vale la pena salir a buscar otros.', 'url' => '/estadisticas', 'impacto' => 0]; }
        // 11. Horas pico sin cobertura: ventas concentradas en pocas horas
        $horas = Comprobante::withoutGlobalScopes()->where('business_id', $id)->where('direccion', 'venta')->where('estado', 'emitido')->whereNotNull('emitido_en')->where('fecha', '>=', $hoy->copy()->subDays(30))->selectRaw("CAST(strftime('%H', emitido_en) AS INTEGER) h, COUNT(*) n")->groupBy('h')->orderByDesc('n')->get();
        if ($horas->count() >= 4) { $pico = $horas->first(); $h[] = ['cat' => 'operacion', 'sev' => 'info', 'titulo' => "La hora pico es a las {$pico->h}:00", 'detalle' => "{$pico->n} tickets en 30 días a esa hora. Conviene tener más gente en caja y depósito en ese horario.", 'url' => '/estadisticas', 'impacto' => 0]; }

        $orden = ['critica' => 0, 'aviso' => 1, 'info' => 2, 'ok' => 3];
        usort($h, fn($a, $c) => [$orden[$a['sev']], -$a['impacto']] <=> [$orden[$c['sev']], -$c['impacto']]);
        return $h;
    }

    // Informe en palabras (IA) o, sin clave, un resumen armado con los hallazgos.
    public function informe(Business $b, array $hallazgos): array
    {
        if (! $hallazgos) return ['texto' => 'No encontré nada que llame la atención. El negocio está andando parejo: ventas estables, cobranza al día y stock en movimiento.', 'modo' => 'basico'];
        if ($key = config('services.anthropic.api_key')) {
            try {
                $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 700, 'system' => "Sos el analista de negocio de {$b->name}, una PyME argentina. Te paso hallazgos de sus datos. Escribí un informe corto (máximo 180 palabras) en español rioplatense, directo y sin tecnicismos, para el dueño: qué es lo más importante, por qué, y 3 acciones concretas para esta semana. Sin títulos ni listas con viñetas.", 'messages' => [['role' => 'user', 'content' => json_encode($hallazgos, JSON_UNESCAPED_UNICODE)]]]);
                if ($r->successful()) return ['texto' => collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"), 'modo' => 'ia'];
            } catch (\Throwable $e) {}
        }
        $crit = array_filter($hallazgos, fn($x) => $x['sev'] === 'critica'); $avisos = array_filter($hallazgos, fn($x) => $x['sev'] === 'aviso');
        $t = count($crit) ? 'Lo urgente: ' . implode('; ', array_map(fn($x) => lcfirst($x['titulo']), array_slice($crit, 0, 3))) . '. ' : 'No hay nada urgente. ';
        $t .= count($avisos) ? 'Para atender esta semana: ' . implode('; ', array_map(fn($x) => lcfirst($x['titulo']), array_slice($avisos, 0, 3))) . '. ' : '';
        $t .= 'Entrá a cada punto para ver el detalle y qué hacer.';
        return ['texto' => $t, 'modo' => 'basico'];
    }
}
