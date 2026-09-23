<?php

namespace App\Http\Controllers\Estadisticas;

use App\Http\Controllers\Controller;
use App\Models\Cobro;
use App\Models\CobroMedio;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// Estadísticas comerciales: ventas por período, sucursal, cliente, artículo, rubro, vendedor; compras y cobranzas.
class EstadisticasController extends Controller
{
    public function index(Request $request)
    {
        $desde = $request->desde ? Carbon::parse($request->desde) : now()->startOfMonth();
        $hasta = $request->hasta ? Carbon::parse($request->hasta) : now()->endOfMonth();
        $sucursal = $request->sucursal ? (int) $request->sucursal : null;
        $dias = $desde->diffInDays($hasta) + 1;
        [$desdePrev, $hastaPrev] = [$desde->copy()->subDays($dias), $hasta->copy()->subDays($dias)];

        $signo = "CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -1 ELSE 1 END";
        $ventas = fn($d, $h) => Comprobante::ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC', 'NCA', 'NCB', 'NCC'])->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->when($sucursal, fn($q) => $q->where('business_location_id', $sucursal));
        $itemsVenta = fn() => ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC'])->whereBetween('comprobantes.fecha', [$desde->toDateString(), $hasta->toDateString()])->when($sucursal, fn($q) => $q->where('comprobantes.business_location_id', $sucursal));
        $sig = "CASE WHEN comprobantes.tipo IN ('NCA','NCB','NCC') THEN -1 ELSE 1 END";

        $total = (float) $ventas($desde, $hasta)->selectRaw("COALESCE(SUM(({$signo}) * total),0) s")->value('s');
        $totalPrev = (float) $ventas($desdePrev, $hastaPrev)->selectRaw("COALESCE(SUM(({$signo}) * total),0) s")->value('s');
        $neto = (float) $ventas($desde, $hasta)->selectRaw("COALESCE(SUM(({$signo}) * (neto - descuento)),0) s")->value('s');
        $cant = $ventas($desde, $hasta)->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->count();
        $cantPrev = $ventas($desdePrev, $hastaPrev)->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->count();
        $costo = (float) $itemsVenta()->join('products', 'products.id', '=', 'comprobante_items.product_id')->selectRaw("COALESCE(SUM(({$sig}) * comprobante_items.cantidad * products.cost),0) s")->value('s');
        $clientesActivos = $ventas($desde, $hasta)->distinct('contact_id')->count('contact_id');
        $pct = fn($a, $b) => $b > 0 ? round(($a - $b) / $b * 100, 1) : null;

        // Serie diaria / mensual según el largo del período
        $porMes = $dias > 62;
        $serie = $ventas($desde, $hasta)->selectRaw(($porMes ? \App\Support\Sql::mes('fecha') : 'fecha') . " as k, SUM(({$signo}) * total) as monto, SUM(CASE WHEN tipo IN ('FA','FB','FC','FE') THEN 1 ELSE 0 END) as n")->groupBy('k')->orderBy('k')->get()
            ->map(fn($r) => ['label' => $porMes ? Carbon::parse($r->k . '-01')->locale('es')->isoFormat('MMM YY') : Carbon::parse($r->k)->format('d/m'), 'monto' => (float) $r->monto, 'n' => (int) $r->n]);

        $top = fn($q, $n = 10) => $q->limit($n)->get();

        // Comparativo con el mismo período del año anterior, en pesos nominales y "de hoy" (reexpresado por IPC si hay índices).
        [$desdeAnt, $hastaAnt] = [$desde->copy()->subYear(), $hasta->copy()->subYear()];
        $totalAnt = (float) $ventas($desdeAnt, $hastaAnt)->selectRaw("COALESCE(SUM(({$signo}) * total),0) s")->value('s');
        $cantAnt = $ventas($desdeAnt, $hastaAnt)->whereIn('tipo', ['FA', 'FB', 'FC', 'FE'])->count();
        $ipcHoy = \App\Models\IndiceIpc::orderByDesc('periodo')->first();
        $coefAnt = $ipcHoy ? \App\Models\IndiceIpc::coeficiente($hastaAnt->format('Y-m'), $ipcHoy->periodo) : null;
        $coefAct = $ipcHoy ? \App\Models\IndiceIpc::coeficiente($hasta->format('Y-m'), $ipcHoy->periodo) : null;
        $comparativo = [
            'anterior' => ['desde' => $desdeAnt->toDateString(), 'hasta' => $hastaAnt->toDateString(), 'ventas' => $totalAnt, 'comprobantes' => $cantAnt, 'ticket' => $cantAnt ? round($totalAnt / $cantAnt, 2) : 0],
            'var_nominal' => $pct($total, $totalAnt),
            'pesos_hoy' => $coefAnt && $coefAct ? ['anterior' => round($totalAnt * $coefAnt, 2), 'actual' => round($total * $coefAct, 2), 'var_real' => $pct($total * $coefAct, $totalAnt * $coefAnt), 'ipc' => $ipcHoy->periodo] : null,
            'mensual' => collect(range(11, 0))->map(function ($i) use ($ventas, $signo, $ipcHoy) {
                $m = now()->subMonths($i); $mAnt = $m->copy()->subYear();
                $v = (float) $ventas($m->copy()->startOfMonth(), $m->copy()->endOfMonth())->selectRaw("COALESCE(SUM(({$signo}) * total),0) s")->value('s');
                $va = (float) $ventas($mAnt->copy()->startOfMonth(), $mAnt->copy()->endOfMonth())->selectRaw("COALESCE(SUM(({$signo}) * total),0) s")->value('s');
                $c = $ipcHoy ? \App\Models\IndiceIpc::coeficiente($m->format('Y-m'), $ipcHoy->periodo) : null;
                return ['mes' => $m->locale('es')->isoFormat('MMM YY'), 'actual' => $v, 'anterior' => $va, 'actual_hoy' => $c ? round($v * $c, 2) : null];
            }),
        ];

        if ($request->export) {
            $csv = "Sección;Nombre;Monto;Cantidad\n";
            $add = function ($sec, $rows, $n = 'nombre', $m = 'monto', $c = 'n') use (&$csv) { foreach ($rows as $r) $csv .= implode(';', [$sec, str_replace(';', ',', (string) ($r[$n] ?? $r->$n ?? '')), number_format((float) ($r[$m] ?? $r->$m ?? 0), 2, ',', ''), (string) ($r[$c] ?? $r->$c ?? '')]) . "\n"; };
            $add('Serie', $serie, 'label', 'monto', 'n');
            $add('Sucursal', $ventas($desde, $hasta)->join('business_locations', 'business_locations.id', '=', 'comprobantes.business_location_id')->selectRaw("business_locations.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('business_locations.name')->get());
            $add('Cliente', $ventas($desde, $hasta)->join('contacts', 'contacts.id', '=', 'comprobantes.contact_id')->selectRaw("contacts.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('contacts.name')->orderByDesc('monto')->get());
            $add('Artículo', $itemsVenta()->join('products', 'products.id', '=', 'comprobante_items.product_id')->selectRaw("products.name as nombre, SUM(({$sig}) * comprobante_items.total) as monto, SUM(({$sig}) * comprobante_items.cantidad) as n")->groupBy('products.name')->orderByDesc('monto')->get());
            $add('Vendedor', $ventas($desde, $hasta)->join('users', 'users.id', '=', 'comprobantes.user_id')->selectRaw("users.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('users.name')->get());
            $add('Hora', $ventas($desde, $hasta)->whereNotNull('emitido_en')->selectRaw("" . \App\Support\Sql::hora('emitido_en') . " as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('nombre')->orderBy('nombre')->get());
            $add('Comparativo mensual', $comparativo['mensual'], 'mes', 'actual', 'anterior');
            \App\Models\AuditLog::registrar('exportar', null, "Exportó estadísticas {$desde->toDateString()} a {$hasta->toDateString()}");
            return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=estadisticas_{$desde->toDateString()}_{$hasta->toDateString()}.csv"]);
        }

        return Inertia::render('Estadisticas/Index', [
            'comparativo' => $comparativo,
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()], 'sucursalId' => $sucursal,
            'listaSucursales' => $request->user()->business->locations()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'kpis' => ['ventas' => $total, 'ventas_var' => $pct($total, $totalPrev), 'comprobantes' => $cant, 'comprobantes_var' => $pct($cant, $cantPrev), 'ticket' => $cant ? round($total / $cant, 2) : 0, 'margen' => $neto > 0 ? round(($neto - $costo) / $neto * 100, 1) : null, 'margen_monto' => round($neto - $costo, 2), 'clientes' => $clientesActivos],
            'serie' => $serie,
            'porSucursal' => $ventas($desde, $hasta)->join('business_locations', 'business_locations.id', '=', 'comprobantes.business_location_id')->selectRaw("business_locations.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('business_locations.name')->orderByDesc('monto')->get(),
            'clientes' => $top($ventas($desde, $hasta)->join('contacts', 'contacts.id', '=', 'comprobantes.contact_id')->selectRaw("contacts.id, contacts.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('contacts.id', 'contacts.name')->orderByDesc('monto')),
            'articulos' => $top($itemsVenta()->join('products', 'products.id', '=', 'comprobante_items.product_id')->selectRaw("products.id, products.name as nombre, products.unit, SUM(({$sig}) * comprobante_items.cantidad) as cantidad, SUM(({$sig}) * comprobante_items.total) as monto, SUM(({$sig}) * (comprobante_items.neto - comprobante_items.cantidad * products.cost)) as margen")->groupBy('products.id', 'products.name', 'products.unit')->orderByDesc('monto')),
            'rubros' => $itemsVenta()->join('products', 'products.id', '=', 'comprobante_items.product_id')->leftJoin('rubros', 'rubros.id', '=', 'products.rubro_id')->selectRaw("COALESCE(rubros.nombre, 'Sin rubro') as nombre, rubros.color, SUM(({$sig}) * comprobante_items.total) as monto")->groupBy('rubros.nombre', 'rubros.color')->orderByDesc('monto')->get(),
            'vendedores' => $ventas($desde, $hasta)->join('users', 'users.id', '=', 'comprobantes.user_id')->selectRaw("users.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('users.name')->orderByDesc('monto')->get(),
            'cobros' => CobroMedio::join('cobros', 'cobros.id', '=', 'cobro_medios.cobro_id')->where('cobros.estado', '!=', 'anulado')->whereBetween('cobros.fecha', [$desde->toDateString(), $hasta->toDateString()])->when($sucursal, fn($q) => $q->where('cobros.business_location_id', $sucursal))->selectRaw('cobro_medios.medio, SUM(cobro_medios.monto) as monto, COUNT(*) as n')->groupBy('cobro_medios.medio')->orderByDesc('monto')->get()->map(fn($r) => ['medio' => Cobro::MEDIOS[$r->medio] ?? $r->medio, 'monto' => (float) $r->monto, 'n' => $r->n]),
            'proveedores' => $top(Comprobante::compras()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'NCA', 'NCB', 'NCC'])->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->join('contacts', 'contacts.id', '=', 'comprobantes.contact_id')->selectRaw("contacts.id, contacts.name as nombre, SUM(({$signo}) * total) as monto, COUNT(*) as n")->groupBy('contacts.id', 'contacts.name')->orderByDesc('monto')),
            'horas' => $ventas($desde, $hasta)->whereNotNull('emitido_en')->selectRaw("" . \App\Support\Sql::hora('emitido_en') . " as h, COUNT(*) as n")->groupBy('h')->orderBy('h')->get(),
        ]);
    }
}
