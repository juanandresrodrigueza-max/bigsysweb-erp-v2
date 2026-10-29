<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\PedidoWeb;
use App\Services\Canales\TiendaService;
use App\Services\Canales\WhatsappPedidosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Bandeja de pedidos que entran por tienda, portal, WhatsApp, marketplaces, delivery y menú QR.
class PedidosController extends Controller
{
    public function __construct(private TiendaService $tienda, private \App\Services\Canales\VentasTiendaService $ventas) {}

    private function fila(PedidoWeb $p): array
    {
        return ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'canal' => $p->canal, 'canal_label' => PedidoWeb::CANALES[$p->canal] ?? $p->canal, 'cliente' => $p->cliente, 'contact_id' => $p->contact_id, 'items' => $p->items, 'subtotal' => (float) $p->subtotal, 'envio' => (float) $p->envio, 'descuento' => (float) $p->descuento, 'total' => (float) $p->total, 'entrega' => $p->entrega, 'pago' => $p->pago, 'estado' => $p->estado, 'estado_label' => PedidoWeb::ESTADOS[$p->estado] ?? $p->estado, 'comprobante_id' => $p->comprobante_id, 'comprobante' => $p->comprobante ? $p->comprobante->nombreTipo() . ' ' . $p->comprobante->numeroFormateado() : null, 'link_pago' => $p->comprobante?->link_pago, 'cobrado' => $p->comprobante ? (float) $p->comprobante->saldo <= 0.005 : false, 'texto' => $p->texto_original, 'notas' => $p->notas, 'avisos' => $p->avisos ?? [], 'cargos' => $this->ventas->cargosDe($p), 'listo' => $p->estado === 'nuevo' && in_array($p->canal, ['mercadolibre', 'woocommerce', 'tiendanube', 'shopify'], true) ? $this->ventas->listo($p, $this->ventas->canalDe($p)) : null, 'factura_subida' => $p->factura_subida_at?->format('d/m H:i'), 'factura_enviada' => $p->factura_enviada_at?->format('d/m H:i'), 'devolucion' => $p->devolucion, 'tracking' => $p->tracking, 'es_tienda' => in_array($p->canal, ['mercadolibre', 'woocommerce', 'tiendanube', 'shopify'], true), 'factura_por' => $p->canal === 'mercadolibre' ? $this->ventas->canalDe($p)?->cfg('factura_por') : null, 'creado' => $p->created_at->format('d/m H:i'), 'hace' => $p->created_at->diffForHumans(), 'url_publica' => $p->urlPublica(), 'external_id' => $p->external_id, 'envio_datos' => $p->envio_datos];
    }

    public function index(Request $request)
    {
        $q = PedidoWeb::with('comprobante:id,tipo,punto_venta,numero,saldo,link_pago')->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->whereNotIn('estado', ['entregado', 'cancelado']))->when($request->canal, fn($q, $c) => $q->where('canal', $c))->latest();
        $hoy = PedidoWeb::whereDate('created_at', today());
        return Inertia::render('Comprobantes/Pedidos', [
            'pedidos' => $q->limit(200)->get()->map(fn($p) => $this->fila($p)), 'filtros' => $request->only('estado', 'canal'), 'canales' => PedidoWeb::CANALES, 'estados' => PedidoWeb::ESTADOS,
            'kpis' => ['nuevos' => PedidoWeb::where('estado', 'nuevo')->count(), 'en_curso' => PedidoWeb::whereIn('estado', ['confirmado', 'preparando', 'enviado'])->count(), 'hoy' => (clone $hoy)->count(), 'hoy_monto' => (float) (clone $hoy)->where('estado', '!=', 'cancelado')->sum('total')],
            'abrirId' => (int) $request->abrir ?: null, 'tiendaActiva' => (bool) ($this->tienda->config($request->user()->business)['activa']), 'tiendaUrl' => url('/t/' . $this->tienda->config($request->user()->business)['slug']),
            'interpretacion' => session('interpretacion'),
        ]);
    }

    public function confirmar(Request $request, int $id)
    {
        $d = $request->validate(['tipo' => 'nullable|in:FX,PRE', 'emitir' => 'boolean', 'entrega_pendiente' => 'boolean']);
        $p = $this->ventas->confirmar(PedidoWeb::findOrFail($id), ['tipo' => $d['tipo'] ?? 'FX', 'emitir' => $d['emitir'] ?? true, 'entrega_pendiente' => $d['entrega_pendiente'] ?? false]);
        return back()->with('success', "Pedido {$p->numeroFormateado()} confirmado: " . $p->comprobante->nombreTipo() . ' ' . $p->comprobante->numeroFormateado() . '.');
    }

    public function estado(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:' . implode(',', array_keys(PedidoWeb::ESTADOS)), 'tracking' => 'nullable|string|max:80']);
        $p = PedidoWeb::findOrFail($id);
        $this->tienda->cambiarEstado($p, $d['estado']);
        // Marcar como enviado avisa en la tienda con el seguimiento: la tienda le manda el mail al comprador.
        if ($d['estado'] === 'enviado') $this->ventas->marcarEnviado($p, $d['tracking'] ?? null);
        return back()->with('success', "{$p->numeroFormateado()}: " . PedidoWeb::ESTADOS[$d['estado']] . '.');
    }

    // Pegás el mensaje de WhatsApp, el sistema lo interpreta y lo mostrás antes de crear el pedido.
    public function interpretar(Request $request, WhatsappPedidosService $wa)
    {
        $d = $request->validate(['texto' => 'required|string|max:2000', 'telefono' => 'nullable|string|max:40']);
        $i = $wa->interpretar($request->user()->business, $d['texto']);
        return back()->with('interpretacion', $i + ['telefono' => $d['telefono'] ?? null]);
    }

    public function crearWhatsapp(Request $request, WhatsappPedidosService $wa)
    {
        $d = $request->validate(['texto' => 'required|string|max:2000', 'telefono' => 'nullable|string|max:40', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.cantidad' => 'required|numeric|min:0.001', 'nombre' => 'nullable|string|max:120', 'direccion' => 'nullable|string|max:200', 'entrega' => 'nullable|in:retiro,envio', 'notas' => 'nullable|string|max:500']);
        $p = $wa->crearDesdeTexto($request->user()->business, $d['texto'], $d['telefono'] ?? null, ['items' => $d['items'], 'nombre' => $d['nombre'] ?? null, 'direccion' => $d['direccion'] ?? null, 'entrega' => $d['entrega'] ?? 'retiro', 'notas' => $d['notas'] ?? null]);
        return redirect("/comprobantes/pedidos?abrir={$p->id}")->with('success', "Pedido {$p->numeroFormateado()} creado desde WhatsApp.");
    }

    // Mercado Envíos: etiqueta PDF para pegar en el paquete y consulta de estado/tracking.
    public function etiqueta(Request $request, int $id, \App\Services\Canales\CanalesService $canales)
    {
        $p = PedidoWeb::findOrFail($id);
        $sid = $p->envio_datos['shipment_id'] ?? null; abort_unless($sid, 422, 'Este pedido no tiene envío de Mercado Envíos.');
        try { $pdf = $canales->mlEtiqueta($request->user()->business, $sid); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=etiqueta_{$sid}.pdf"]);
    }

    public function envioEstado(Request $request, int $id, \App\Services\Canales\CanalesService $canales)
    {
        $p = PedidoWeb::findOrFail($id);
        try { $d = $canales->mlEnvioEstado($request->user()->business, $p); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
        return back()->with('success', "Envío {$d['shipment_id']}: " . ($d['estado'] ?? 'sin estado') . ($d['tracking'] ? " · tracking {$d['tracking']}" : '') . '.');
    }

    // ---- Fase 30.4/30.6: la factura en MercadoLibre, devoluciones y exportar ----
    public function subirFactura(int $id) { $this->ventas->subirFacturaMl(PedidoWeb::findOrFail($id)); return back()->with('success', 'Factura cargada en la venta de MercadoLibre.'); }
    public function mandarFactura(int $id) { $this->ventas->mandarFacturaMl(PedidoWeb::findOrFail($id)); return back()->with('success', 'Factura enviada al comprador por el mensaje de la venta.'); }
    public function devolver(int $id) { $p = $this->ventas->devolverStock(PedidoWeb::findOrFail($id)); return back()->with('success', "{$p->numeroFormateado()}: el stock volvió."); }
    public function devolucionResuelta(int $id) { PedidoWeb::findOrFail($id)->forceFill(['devolucion' => 'resuelta', 'estado' => 'cancelado'])->save(); return back()->with('success', 'Marcado como resuelto.'); }

    public function exportar(Request $request, \App\Services\Canales\ExportacionPedidosService $svc)
    {
        $d = $request->validate(['desde' => 'required|date', 'hasta' => 'required|date|after_or_equal:desde', 'plataforma' => 'nullable|string|max:20', 'estado' => 'nullable|string|max:15', 'formato' => 'nullable|in:csv,xlsx', 'renglones' => 'boolean', 'excel_es' => 'boolean', 'contar' => 'boolean']);
        $pedidos = $svc->pedidos($d['desde'], $d['hasta'], $d['plataforma'] ?? null, $d['estado'] ?? null);
        if ($request->boolean('contar')) return response()->json(['cantidad' => $pedidos->count(), 'total' => round($pedidos->where('estado', '!=', 'cancelado')->sum('total'), 2)]);
        abort_if($pedidos->isEmpty(), 422, 'No hay pedidos en ese período: no se baja un archivo vacío.');
        $f = $svc->filas($pedidos, $request->boolean('renglones'));
        $nombre = "pedidos-{$d['desde']}-a-{$d['hasta']}";
        $cabR = ['id_pedido', 'codigo', 'descripcion', 'cantidad', 'precio', 'importe'];
        if (($d['formato'] ?? 'csv') === 'xlsx' && $pedidos->count() <= 25000) {
            $hojas = ['Pedidos' => array_merge([\App\Services\Canales\ExportacionPedidosService::CABECERA], $f['cabecera'])];
            if ($request->boolean('renglones')) $hojas['Renglones'] = array_merge([$cabR], $f['renglones']);
            return response($svc->xlsx($hojas), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename={$nombre}.xlsx"]);
        }
        if ($request->boolean('renglones') && $request->boolean('solo_renglones')) return response($svc->csv($cabR, $f['renglones'], $request->boolean('excel_es')), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename={$nombre}-renglones.csv"]);
        return response($svc->csv(\App\Services\Canales\ExportacionPedidosService::CABECERA, $f['cabecera'], $request->boolean('excel_es')), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename={$nombre}.csv"]);
    }

    public function responder(Request $request, int $id, WhatsappPedidosService $wa)
    {
        $d = $request->validate(['texto' => 'required|string|max:1000']);
        $p = PedidoWeb::findOrFail($id);
        $tel = $p->cliente['telefono'] ?? null; abort_if(! $tel, 422, 'El pedido no tiene teléfono.');
        $r = $wa->responder($request->user()->business, $tel, $d['texto']);
        return $r['enviado'] ? back()->with('success', 'Mensaje enviado por WhatsApp.') : back()->with('success', 'Abrí WhatsApp para enviar el mensaje.')->with('abrir', $r['link']);
    }
}
