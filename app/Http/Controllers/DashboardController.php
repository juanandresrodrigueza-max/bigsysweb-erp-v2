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
        $vencido   = (float) Comprobante::ventas()->pendientesCobro()->whereDate('fecha_vto', '<', today())->sum('saldo');
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

        return Inertia::render('Dashboard', compact('kpis', 'serie', 'ultimas', 'destacadas', 'periodo', 'operacion'));
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
