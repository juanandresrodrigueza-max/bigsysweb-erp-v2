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
    public function __construct(private TiendaService $tienda) {}

    private function fila(PedidoWeb $p): array
    {
        return ['id' => $p->id, 'numero' => $p->numeroFormateado(), 'canal' => $p->canal, 'canal_label' => PedidoWeb::CANALES[$p->canal] ?? $p->canal, 'cliente' => $p->cliente, 'contact_id' => $p->contact_id, 'items' => $p->items, 'subtotal' => (float) $p->subtotal, 'envio' => (float) $p->envio, 'descuento' => (float) $p->descuento, 'total' => (float) $p->total, 'entrega' => $p->entrega, 'pago' => $p->pago, 'estado' => $p->estado, 'estado_label' => PedidoWeb::ESTADOS[$p->estado] ?? $p->estado, 'comprobante_id' => $p->comprobante_id, 'comprobante' => $p->comprobante ? $p->comprobante->nombreTipo() . ' ' . $p->comprobante->numeroFormateado() : null, 'link_pago' => $p->comprobante?->link_pago, 'cobrado' => $p->comprobante ? (float) $p->comprobante->saldo <= 0.005 : false, 'texto' => $p->texto_original, 'notas' => $p->notas, 'creado' => $p->created_at->format('d/m H:i'), 'hace' => $p->created_at->diffForHumans(), 'url_publica' => $p->urlPublica(), 'external_id' => $p->external_id];
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
        $p = $this->tienda->confirmar(PedidoWeb::findOrFail($id), ['tipo' => $d['tipo'] ?? 'FX', 'emitir' => $d['emitir'] ?? true, 'entrega_pendiente' => $d['entrega_pendiente'] ?? false]);
        return back()->with('success', "Pedido {$p->numeroFormateado()} confirmado: " . $p->comprobante->nombreTipo() . ' ' . $p->comprobante->numeroFormateado() . '.');
    }

    public function estado(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:' . implode(',', array_keys(PedidoWeb::ESTADOS))]);
        $p = PedidoWeb::findOrFail($id);
        $this->tienda->cambiarEstado($p, $d['estado']);
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

    public function responder(Request $request, int $id, WhatsappPedidosService $wa)
    {
        $d = $request->validate(['texto' => 'required|string|max:1000']);
        $p = PedidoWeb::findOrFail($id);
        $tel = $p->cliente['telefono'] ?? null; abort_if(! $tel, 422, 'El pedido no tiene teléfono.');
        $r = $wa->responder($request->user()->business, $tel, $d['texto']);
        return $r['enviado'] ? back()->with('success', 'Mensaje enviado por WhatsApp.') : back()->with('success', 'Abrí WhatsApp para enviar el mensaje.')->with('abrir', $r['link']);
    }
}
