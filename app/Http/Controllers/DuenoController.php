<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\PedidoWeb;
use App\Services\Ventas\CobranzasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// "Mi negocio": el panel del dueño para el celular. Una pantalla, los números de hoy y lo que hay que atender, con acciones de un toque.
class DuenoController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user(); $hoy = today()->toDateString(); $ayer = today()->subDay()->toDateString();
        $ventas = fn($d, $h) => Comprobante::ventas()->emitidos()->whereIn('tipo', ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC', 'NCA', 'NCB', 'NCC'])->whereBetween('fecha', [$d, $h]);
        $suma = fn($q) => (float) $q->selectRaw("COALESCE(SUM(CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -total ELSE total END),0) s")->value('s');
        $vHoy = $suma($ventas($hoy, $hoy)); $vAyer = $suma($ventas($ayer, $ayer)); $vMes = $suma($ventas(today()->startOfMonth()->toDateString(), $hoy));
        $vMesAnt = $suma($ventas(today()->subMonth()->startOfMonth()->toDateString(), today()->subMonth()->day(min(today()->day, today()->subMonth()->daysInMonth))->toDateString()));
        $cobradoHoy = (float) Cobro::where('estado', '!=', 'anulado')->where('fecha', $hoy)->sum('total');
        $cuentas = CuentaFondos::where('activa', true)->get(['id', 'tipo', 'nombre', 'saldo']);
        $deud = app(CobranzasService::class)->resumenDeudores();
        $topHoy = ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', $u->business_id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->where('comprobantes.fecha', $hoy)->whereNotNull('comprobante_items.product_id')
            ->leftJoin('products', 'products.id', '=', 'comprobante_items.product_id')->selectRaw('products.name as nombre, SUM(comprobante_items.cantidad) as cantidad, SUM(comprobante_items.total) as total')->groupBy('products.name')->orderByDesc('total')->limit(5)->get();
        $ultimas = Comprobante::ventas()->emitidos()->with('contact:id,name')->orderByDesc('emitido_en')->limit(6)->get()->map(fn($c) => ['id' => $c->id, 'tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'cliente' => $c->contact?->name ?? 'Consumidor final', 'total' => (float) $c->total, 'hora' => $c->emitido_en?->format('H:i'), 'estado_cobro' => $c->estadoCobro()]);
        $pct = fn($a, $b) => $b > 0 ? round(($a - $b) / $b * 100) : null;
        return Inertia::render('Dueno', [
            'hoy' => ['ventas' => $vHoy, 'vs_ayer' => $pct($vHoy, $vAyer), 'cobrado' => $cobradoHoy, 'facturas' => $ventas($hoy, $hoy)->facturas()->count()],
            'mes' => ['ventas' => $vMes, 'vs_mes_anterior' => $pct($vMes, $vMesAnt)],
            'fondos' => ['caja' => (float) $cuentas->where('tipo', 'caja')->sum('saldo'), 'banco' => (float) $cuentas->where('tipo', 'banco')->sum('saldo'), 'billetera' => (float) $cuentas->where('tipo', 'billetera')->sum('saldo')],
            'cobrar' => $deud,
            'pagar' => ['total' => (float) Contact::suppliers()->where('balance', '>', 0)->sum('balance'), 'vencido' => (float) Comprobante::compras()->pendientesPago()->where('fecha_vto', '<', $hoy)->sum('saldo')],
            'pedidos_web' => class_exists(PedidoWeb::class) ? PedidoWeb::where('estado', 'nuevo')->count() : 0,
            'alertas' => Alerta::visiblesPara($u)->activas()->orderByRaw("CASE severidad WHEN 'critica' THEN 0 WHEN 'aviso' THEN 1 ELSE 2 END")->latest()->limit(5)->get()->map(fn($a) => ['id' => $a->id, 'titulo' => $a->titulo, 'severidad' => $a->severidad, 'url' => $a->url]),
            'top_hoy' => $topHoy->map(fn($r) => ['nombre' => $r->nombre, 'cantidad' => (float) $r->cantidad, 'total' => (float) $r->total]),
            'ultimas' => $ultimas,
            'pendientes_cae' => Comprobante::ventas()->where('estado', 'emitido')->where('afip_estado', 'pendiente')->count(),
        ]);
    }
}
