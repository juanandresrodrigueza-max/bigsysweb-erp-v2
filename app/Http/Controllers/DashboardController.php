<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->business_id) return redirect($request->user()->is_superadmin ? '/admin' : '/login');
        $user     = $request->user();
        $periodo  = $request->input('periodo', 'mes');
        $sucursal = $user->current_location_id;
        [$desde, $hasta]         = $this->rango($periodo);
        [$desdePrev, $hastaPrev] = $this->rangoAnterior($desde, $hasta);

        // Ventas netas = facturas + ND - NC emitidas en el período
        $ventas = fn($d, $h) => Comprobante::ventas()->emitidos()->deSucursal($sucursal)->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->whereIn('tipo', array_keys(array_filter(Comprobante::TIPOS, fn($t) => $t['cc'] !== 0)));
        $signo = "CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -total ELSE total END";

        $ventasTotal  = (float) $ventas($desde, $hasta)->selectRaw("COALESCE(SUM({$signo}),0) as s")->value('s');
        $ventasPrev   = (float) $ventas($desdePrev, $hastaPrev)->selectRaw("COALESCE(SUM({$signo}),0) as s")->value('s');
        $cantidad     = $ventas($desde, $hasta)->facturas()->count();
        $cantidadPrev = $ventas($desdePrev, $hastaPrev)->facturas()->count();
        $ticket       = $cantidad ? $ventasTotal / $cantidad : 0;
        $ticketPrev   = $cantidadPrev ? $ventasPrev / $cantidadPrev : 0;

        $comprasTotal = (float) Purchase::deSucursal($sucursal)->whereBetween('created_at', [$desde, $hasta])->sum('total');
        $comprasPrev  = (float) Purchase::deSucursal($sucursal)->whereBetween('created_at', [$desdePrev, $hastaPrev])->sum('total');

        $porCobrar = (float) Contact::customers()->where('balance', '>', 0)->sum('balance');
        $vencido   = (float) Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo');
        $porPagar  = (float) Contact::suppliers()->where('balance', '>', 0)->sum('balance');
        $fondos    = (float) \App\Models\CuentaFondos::where('activa', true)->sum('saldo');
        $sinStock  = Product::where('active', true)->whereColumn('stock', '<=', 'stock_min')->count();

        $kpis = [
            ['key' => 'ventas',  'label' => 'Ventas',          'valor' => $ventasTotal, 'formato' => 'moneda', 'tendencia' => $this->tendencia($ventasTotal, $ventasPrev), 'url' => '/comprobantes?grupo=factura'],
            ['key' => 'ticket',  'label' => 'Ticket promedio', 'valor' => $ticket,      'formato' => 'moneda', 'tendencia' => $this->tendencia($ticket, $ticketPrev)],
            ['key' => 'cobrar',  'label' => 'Por cobrar',      'valor' => $porCobrar,   'formato' => 'moneda', 'url' => '/clientes?estado=deudores'],
            ['key' => 'vencido', 'label' => 'Vencido',         'valor' => $vencido,     'formato' => 'moneda', 'url' => '/comprobantes?estado=vencido', 'alerta' => $vencido > 0],
            ['key' => 'pagar',   'label' => 'Por pagar',       'valor' => $porPagar,    'formato' => 'moneda', 'url' => '/proveedores?estado=deudores'],
            ['key' => 'fondos',  'label' => 'Disponible',      'valor' => $fondos,      'formato' => 'moneda', 'url' => '/fondos'],
            ['key' => 'stock',   'label' => 'Bajo mínimo',     'valor' => $sinStock,    'formato' => 'entero', 'url' => '/stock', 'alerta' => $sinStock > 0],
        ];

        $serie = $ventas($desde, $hasta)
            ->select('fecha as dia', DB::raw("SUM({$signo}) as monto"))
            ->groupBy('fecha')->orderBy('fecha')->get()
            ->map(fn($r) => ['fecha' => (string) $r->dia, 'label' => Carbon::parse($r->dia)->format('d/m'), 'monto' => (float) $r->monto]);

        $ultimas = Comprobante::ventas()->emitidos()->deSucursal($sucursal)->with('contact:id,name')->orderByDesc('emitido_en')->limit(8)->get()
            ->map(fn($c) => ['id' => $c->id, 'tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'cliente' => $c->contact?->name ?? 'Consumidor final', 'total' => (float) $c->total, 'estado_cobro' => $c->estadoCobro(), 'fecha' => $c->fecha->format('d/m')]);

        $destacadas = Alerta::visiblesPara($user)->activas()->orderByRaw("CASE severidad WHEN 'critica' THEN 0 WHEN 'aviso' THEN 1 ELSE 2 END")->latest()->limit(6)->get()
            ->map(fn($a) => ['id' => $a->id, 'titulo' => $a->titulo, 'detalle' => $a->detalle, 'severidad' => $a->severidad, 'url' => $a->url, 'hace' => $a->created_at->diffForHumans()]);

        // Operación: stock y producción (crece con cada fase)
        $operacion = null;
        if ($user->puede('stock')) {
            $prodActivos = Product::where('active', true)->where('controla_stock', true);
            $operacion = [
                'valorizado' => (float) (clone $prodActivos)->selectRaw('COALESCE(SUM(stock * cost),0) as v')->value('v'),
                'bajo_minimo' => (clone $prodActivos)->whereColumn('stock', '<=', 'stock_min')->count(),
                'sin_stock' => (clone $prodActivos)->where('stock', '<=', 0)->count(),
                'faltantes' => (clone $prodActivos)->whereColumn('stock', '<=', 'stock_min')->orderByRaw('stock - stock_min')->limit(5)->get()->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'stock' => (float) $p->stock, 'min' => (float) $p->stock_min, 'unit' => $p->unit]),
                'resultado' => $user->puede('contable') ? app(\App\Services\Contabilidad\ContabilidadService::class)->resultado($user->business_id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()) : null,
                'produccion' => $user->puede('produccion') ? [
                    'en_curso' => \App\Models\ProductionOrder::where('status', 'in_progress')->count(), 'pendientes' => \App\Models\ProductionOrder::where('status', 'pending')->count(),
                    'atrasadas' => \App\Models\ProductionOrder::whereIn('status', ['pending', 'in_progress'])->whereNotNull('scheduled_at')->where('scheduled_at', '<', now()->startOfDay())->count(),
                    'terminadas_mes' => \App\Models\ProductionOrder::where('status', 'completed')->where('completed_at', '>=', now()->startOfMonth())->count(),
                ] : null,
            ];
        }

        return Inertia::render('Dashboard', compact('kpis', 'serie', 'ultimas', 'destacadas', 'periodo', 'operacion') + ['panel' => $this->panelRol($user, $desde, $hasta)]);
    }

    // Panel por rol: lo que esa persona tiene que mirar hoy (vendedor, cajero, depósito, contador). El dueño/admin ve todo lo demás.
    private function panelRol($user, Carbon $desde, Carbon $hasta): ?array
    {
        $slug = $user->rolActual()?->slug; if ($user->esDueno() || in_array($slug, [null, 'dueno', 'admin'], true)) return null;
        $f = [$desde->toDateString(), $hasta->toDateString()];
        if ($slug === 'vendedor') {
            $v = \App\Models\Vendedor::deUsuario($user->id);
            $mias = Comprobante::ventas()->emitidos()->facturas()->whereBetween('fecha', $f)->where(fn($q) => $q->where('user_id', $user->id)->when($v, fn($qq) => $qq->orWhere('vendedor_id', $v->id)));
            $pres = Comprobante::ventas()->emitidos()->where('tipo', 'PRE')->whereDoesntHave('derivados')->where('user_id', $user->id)->with('contact:id,name')->orderByDesc('fecha')->limit(5)->get();
            $com = $v ? collect(app(\App\Services\Comprobantes\ComisionesService::class)->liquidar($f[0], $f[1], $v->id))->first() : null;
            $deud = $v ? Contact::customers()->where('vendedor_id', $v->id)->where('balance', '>', 0.005)->orderByDesc('balance')->limit(5)->get(['id', 'name', 'balance']) : collect();
            return ['tipo' => 'vendedor', 'ventas' => (float) (clone $mias)->sum('total'), 'facturas' => (clone $mias)->count(), 'comision' => $com['total'] ?? null,
                'presupuestos' => $pres->map(fn($p) => ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'cliente' => $p->contact?->name, 'total' => (float) $p->total, 'fecha' => $p->fecha->format('d/m')]),
                'deudores' => $deud->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name, 'saldo' => (float) $c->balance])];
        }
        if ($slug === 'cajero') {
            $caja = \App\Models\CuentaFondos::where('activa', true)->where('tipo', 'caja')->where('business_location_id', $user->current_location_id)->orderByDesc('es_default')->first() ?? \App\Models\CuentaFondos::where('activa', true)->where('tipo', 'caja')->first();
            $turno = $caja?->turnoAbierto;
            $esperado = $turno ? app(\App\Services\Fondos\FondosService::class)->esperadoPorMedio($turno) : [];
            $hoyQ = Comprobante::ventas()->emitidos()->facturas()->where('fecha', today()->toDateString())->where('user_id', $user->id);
            return ['tipo' => 'cajero', 'caja' => $caja ? ['id' => $caja->id, 'nombre' => $caja->nombre, 'saldo' => (float) $caja->saldo] : null,
                'turno' => $turno ? ['id' => $turno->id, 'desde' => $turno->apertura->format('H:i'), 'inicial' => (float) $turno->saldo_inicial, 'esperado' => (float) ($esperado['efectivo'] ?? $caja->saldo), 'medios' => $esperado] : null,
                'ventas_hoy' => (float) (clone $hoyQ)->sum('total'), 'tickets_hoy' => (clone $hoyQ)->count(),
                'cobros_hoy' => (float) \App\Models\Cobro::where('estado', '!=', 'anulado')->where('fecha', today()->toDateString())->where('user_id', $user->id)->sum('total')];
        }
        if ($slug === 'deposito') {
            $prod = Product::where('active', true)->where('controla_stock', true);
            $entregas = Comprobante::ventas()->emitidos()->facturas()->where('entrega_pendiente', true)->with('contact:id,name')->orderBy('fecha')->limit(8)->get()->filter(fn($c) => $c->pendienteEntrega() > 0)->values();
            $transf = class_exists(\App\Models\TransferenciaStock::class) ? \App\Models\TransferenciaStock::where('estado', 'pendiente')->count() : 0;
            return ['tipo' => 'deposito', 'bajo_minimo' => (clone $prod)->whereColumn('stock', '<=', 'stock_min')->count(), 'sin_stock' => (clone $prod)->where('stock', '<=', 0)->count(), 'transferencias_pendientes' => $transf,
                'entregas' => $entregas->map(fn($c) => ['id' => $c->id, 'numero' => $c->numeroFormateado(), 'cliente' => $c->contact?->name, 'pendiente' => $c->pendienteEntrega(), 'fecha' => $c->fecha->format('d/m')]),
                'faltantes' => (clone $prod)->whereColumn('stock', '<=', 'stock_min')->orderByRaw('stock - stock_min')->limit(6)->get()->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'stock' => (float) $p->stock, 'min' => (float) $p->stock_min])];
        }
        if ($slug === 'contador') {
            $mes = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
            $ivaDF = (float) Comprobante::ventas()->emitidos()->whereBetween('fecha', $mes)->selectRaw("COALESCE(SUM(CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -iva ELSE iva END),0) s")->value('s');
            $ivaCF = (float) Comprobante::compras()->emitidos()->whereBetween('fecha', $mes)->selectRaw("COALESCE(SUM(CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -iva ELSE iva END),0) s")->value('s');
            $conAsiento = \App\Models\Asiento::where('estado', 'confirmado')->whereIn('origen', ['venta', 'compra'])->pluck('origen_id');
            $sinAsiento = Comprobante::emitidos()->whereBetween('fecha', $mes)->whereNotIn('tipo', ['PRE', 'REM'])->whereNotIn('id', $conAsiento)->count();
            $res = app(\App\Services\Contabilidad\ContabilidadService::class)->resultado($user->business_id, $mes[0], $mes[1]);
            return ['tipo' => 'contador', 'iva_df' => $ivaDF, 'iva_cf' => $ivaCF, 'posicion_iva' => round($ivaDF - $ivaCF, 2), 'sin_asiento' => $sinAsiento, 'resultado' => $res, 'pendientes_cae' => Comprobante::ventas()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->count(),
                'sueldos_pendientes' => class_exists(\App\Models\Liquidacion::class) ? \App\Models\Liquidacion::where('estado', 'confirmada')->count() : 0];
        }
        return null;
    }

    private function rango(string $periodo): array
    {
        $hoy = now();
        return match ($periodo) {
            'hoy'       => [$hoy->copy()->startOfDay(), $hoy->copy()->endOfDay()],
            'semana'    => [$hoy->copy()->startOfWeek(), $hoy->copy()->endOfWeek()],
            'trimestre' => [$hoy->copy()->startOfQuarter(), $hoy->copy()->endOfQuarter()],
            'anio'      => [$hoy->copy()->startOfYear(), $hoy->copy()->endOfYear()],
            default     => [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()],
        };
    }

    private function rangoAnterior(Carbon $desde, Carbon $hasta): array
    {
        $dias = $desde->diffInDays($hasta) + 1;
        return [$desde->copy()->subDays($dias), $hasta->copy()->subDays($dias)];
    }

    private function tendencia(float $actual, float $anterior): ?float
    {
        if ($anterior == 0.0) {
            return $actual > 0 ? 100.0 : null;
        }
        return round(($actual - $anterior) / $anterior * 100, 1);
    }
}
