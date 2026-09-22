<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
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

        $ventas = fn($d, $h) => Sale::deSucursal($sucursal)->whereBetween('created_at', [$d, $h])->where('status', '!=', 'cancelled');

        $ventasTotal = (float) $ventas($desde, $hasta)->sum('total');
        $ventasPrev  = (float) $ventas($desdePrev, $hastaPrev)->sum('total');
        $cantidad    = $ventas($desde, $hasta)->count();
        $cantidadPrev = $ventas($desdePrev, $hastaPrev)->count();
        $ticket      = $cantidad ? $ventasTotal / $cantidad : 0;
        $ticketPrev  = $cantidadPrev ? $ventasPrev / $cantidadPrev : 0;

        $comprasTotal = (float) Purchase::deSucursal($sucursal)->whereBetween('created_at', [$desde, $hasta])->sum('total');
        $comprasPrev  = (float) Purchase::deSucursal($sucursal)->whereBetween('created_at', [$desdePrev, $hastaPrev])->sum('total');

        $porCobrar = (float) Contact::where('type', '!=', 'supplier')->where('balance', '>', 0)->sum('balance');
        $porPagar  = (float) Contact::where('type', '!=', 'customer')->where('balance', '<', 0)->sum('balance');

        $sinStock  = Product::where('active', true)->whereColumn('stock', '<=', 'stock_min')->count();

        $kpis = [
            ['key' => 'ventas',    'label' => 'Ventas',           'valor' => $ventasTotal,  'formato' => 'moneda', 'tendencia' => $this->tendencia($ventasTotal, $ventasPrev)],
            ['key' => 'ticket',    'label' => 'Ticket promedio',  'valor' => $ticket,       'formato' => 'moneda', 'tendencia' => $this->tendencia($ticket, $ticketPrev)],
            ['key' => 'compras',   'label' => 'Compras',          'valor' => $comprasTotal, 'formato' => 'moneda', 'tendencia' => $this->tendencia($comprasTotal, $comprasPrev), 'invertida' => true],
            ['key' => 'cobrar',    'label' => 'Por cobrar',       'valor' => $porCobrar,    'formato' => 'moneda'],
            ['key' => 'pagar',     'label' => 'Por pagar',        'valor' => abs($porPagar), 'formato' => 'moneda'],
            ['key' => 'stock',     'label' => 'Bajo mínimo',      'valor' => $sinStock,     'formato' => 'entero', 'url' => '/stock'],
        ];

        $serie = $ventas($desde, $hasta)
            ->select(DB::raw('DATE(created_at) as dia'), DB::raw('SUM(total) as monto'))
            ->groupBy('dia')->orderBy('dia')->get()
            ->map(fn($r) => ['fecha' => $r->dia, 'label' => Carbon::parse($r->dia)->format('d/m'), 'monto' => (float) $r->monto]);

        $ultimas = Sale::deSucursal($sucursal)->with('customer:id,name')->latest()->limit(8)->get()
            ->map(fn($v) => ['id' => $v->id, 'cliente' => $v->customer?->name ?? 'Consumidor final', 'total' => (float) $v->total, 'estado' => $v->status, 'fecha' => $v->created_at->format('d/m H:i')]);

        $destacadas = Alerta::visiblesPara($user)->activas()->orderByRaw("CASE severidad WHEN 'critica' THEN 0 WHEN 'aviso' THEN 1 ELSE 2 END")->latest()->limit(6)->get()
            ->map(fn($a) => ['id' => $a->id, 'titulo' => $a->titulo, 'detalle' => $a->detalle, 'severidad' => $a->severidad, 'url' => $a->url, 'hace' => $a->created_at->diffForHumans()]);

        return Inertia::render('Dashboard', compact('kpis', 'serie', 'ultimas', 'destacadas', 'periodo'));
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
