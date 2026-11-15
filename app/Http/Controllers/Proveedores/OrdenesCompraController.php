<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\OrdenCompra;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Compras\OrdenCompraService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrdenesCompraController extends Controller
{
    public function __construct(private OrdenCompraService $service) {}

    public function index(Request $request)
    {
        $q = OrdenCompra::with('contact:id,name')
            ->when($request->estado, fn($q, $e) => $e === 'abiertas' ? $q->whereIn('estado', ['borrador', 'enviada', 'parcial']) : $q->where('estado', $e))
            ->when($request->contact_id, fn($q, $c) => $q->where('contact_id', $c))
            ->orderByDesc('fecha')->orderByDesc('id');
        return Inertia::render('Proveedores/Ordenes', [
            'lista' => $q->paginate(25)->withQueryString()->through(fn($o) => $this->resumir($o)),
            'filtros' => $request->only('estado', 'contact_id'),
            'proveedores' => Contact::suppliers()->orderBy('name')->get(['id', 'name']),
            'estados' => OrdenCompra::ESTADOS,
            // Fase 97: autorización por monto
            'autorizacion' => app(\App\Services\Compras\AutorizacionComprasService::class)->config($request->user()->business) + ['soy_autorizador' => app(\App\Services\Compras\AutorizacionComprasService::class)->esAutorizador($request->user()), 'pendientes' => OrdenCompra::where('autorizacion', 'pendiente')->where('estado', 'borrador')->count()],
            'usuarios' => \App\Models\User::where('business_id', $request->user()->business_id)->where('status', 'active')->whereNotNull('role_id')->orderBy('name')->get(['id', 'name'])->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
            'resumen' => ['abiertas' => OrdenCompra::whereIn('estado', ['borrador', 'enviada', 'parcial'])->count(), 'monto_abierto' => (float) OrdenCompra::whereIn('estado', ['borrador', 'enviada', 'parcial'])->sum('total'), 'atrasadas' => OrdenCompra::whereIn('estado', ['enviada', 'parcial'])->where('fecha_entrega', '<', today()->toDateString())->count()],
        ]);
    }

    public function create(Request $request) { return Inertia::render('Proveedores/OrdenForm', $this->datosForm($request, null)); }

    public function edit(Request $request, int $id)
    {
        $oc = OrdenCompra::with('items')->findOrFail($id);
        abort_if(! in_array($oc->estado, ['borrador', 'enviada'], true), 422, 'Esta orden ya no se edita.');
        return Inertia::render('Proveedores/OrdenForm', $this->datosForm($request, $oc));
    }

    // Fase 62: cotizaciones de varios proveedores (fotos, PDF o el mensaje pegado) → tabla comparada.
    public function cotizacionesLeer(Request $request, \App\Services\Compras\CotizacionesService $svc)
    {
        $d = $request->validate(['archivos' => 'nullable|array|max:8', 'archivos.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:12288', 'textos' => 'nullable|array|max:8', 'textos.*' => 'nullable|string|max:8000']);
        abort_unless(\App\Support\Ia::tiene($request->user()->business), 422, 'Cargá la clave de IA en Configuración → IA para leer cotizaciones.');
        $entradas = [];
        foreach ($request->file('archivos', []) as $f) {
            $mime = (string) $f->getMimeType(); if ($mime !== 'application/pdf' && ! str_starts_with($mime, 'image/')) $mime = strtolower($f->getClientOriginalExtension()) === 'pdf' ? 'application/pdf' : 'image/jpeg';
            $entradas[] = ['base64' => base64_encode(file_get_contents($f->getRealPath())), 'mime' => $mime, 'nombre' => $f->getClientOriginalName()];
        }
        foreach (array_filter($d['textos'] ?? [], fn($t) => trim((string) $t) !== '') as $i => $t) $entradas[] = ['texto' => $t, 'nombre' => 'Mensaje ' . ($i + 1)];
        abort_if(! $entradas, 422, 'Subí al menos una cotización o pegá un mensaje.');
        $r = $svc->leer($request->user()->business, $entradas);
        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    // Fase 62: una orden de compra en borrador por proveedor, con lo que ganó cada uno.
    public function cotizacionesPedidos(Request $request, \App\Services\Compras\CotizacionesService $svc)
    {
        $d = $request->validate(['reparto' => 'required|array|min:1', 'reparto.*.proveedor_id' => 'nullable|integer', 'reparto.*.items' => 'required|array|min:1', 'reparto.*.items.*.product_id' => 'nullable|integer|exists:products,id',
            'reparto.*.items.*.descripcion' => 'nullable|string|max:150', 'reparto.*.items.*.cantidad' => 'required|numeric|min:0.001', 'reparto.*.items.*.precio_unit' => 'required|numeric|min:0', 'notas' => 'nullable|string|max:300']);
        $ocs = $svc->pedidos($d['reparto'], $d['notas'] ?? null);
        $sin = collect($d['reparto'])->filter(fn($r) => empty($r['proveedor_id']))->count();
        return redirect('/proveedores/ordenes?estado=abiertas')->with('success', count($ocs) . ' ' . (count($ocs) === 1 ? 'orden creada' : 'órdenes creadas') . ' en borrador: ' . collect($ocs)->map(fn($o) => $o->numeroFormateado() . ' a ' . $o->contact->name)->implode(', ') . '.' . ($sin ? " {$sin} proveedor(es) sin ficha quedaron afuera: dalos de alta y volvé a comparar." : ''));
    }

    public function store(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'contact_id' => 'required|exists:contacts,id', 'fecha' => 'required|date', 'fecha_entrega' => 'nullable|date', 'notas' => 'nullable|string|max:1000', 'origen' => 'nullable|in:manual,sugerido,faltantes',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|exists:products,id', 'items.*.descripcion' => 'nullable|string|max:150', 'items.*.cantidad' => 'required|numeric|min:0', 'items.*.precio_unit' => 'nullable|numeric|min:0', 'items.*.notas' => 'nullable|string|max:150',
        ]);
        $oc = $this->service->guardar($d, $id ? OrdenCompra::findOrFail($id) : null);
        if ($request->boolean('enviar') && $oc->estado === 'borrador') $oc = $this->service->enviar($oc);
        return redirect("/proveedores/ordenes/{$oc->id}")->with('success', "Orden {$oc->numeroFormateado()} " . ($oc->autorizacion === 'pendiente' ? 'guardada: espera autorización (supera el monto configurado).' : ($request->boolean('enviar') ? 'enviada.' : 'guardada.')));
    }

    public function show(int $id)
    {
        $oc = OrdenCompra::with(['items.product:id,name,sku,unit,stock', 'contact', 'user:id,name', 'compras'])->findOrFail($id);
        return Inertia::render('Proveedores/OrdenVer', [
            'orden' => $this->resumir($oc) + [
                'fecha_entrega' => $oc->fecha_entrega?->format('d/m/Y'), 'notas' => $oc->notas, 'usuario' => $oc->user?->name, 'origen' => $oc->origen, 'enviada_en' => $oc->enviada_en?->format('d/m/Y H:i'),
                'proveedor_email' => $oc->contact?->email, 'proveedor_telefono' => $oc->contact?->phone ?: $oc->contact?->mobile,
                'items' => $oc->items->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'sku' => $i->product?->sku, 'unit' => $i->product?->unit, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'recibido' => (float) $i->recibido, 'pendiente' => $i->pendiente(), 'notas' => $i->notas, 'stock' => (float) ($i->product?->stock ?? 0)]),
                'compras' => $oc->compras->map(fn($c) => ['id' => $c->id, 'nombre' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'total' => (float) $c->total, 'estado' => $c->estado]),
            ],
            'estados' => OrdenCompra::ESTADOS,
            'autorizacion' => ['requiere' => app(\App\Services\Compras\AutorizacionComprasService::class)->requiere($oc, auth()->user()), 'soy_autorizador' => app(\App\Services\Compras\AutorizacionComprasService::class)->esAutorizador(auth()->user()), 'desde' => app(\App\Services\Compras\AutorizacionComprasService::class)->config(auth()->user()->business)['autoriza_desde']], // Fase 97
        ]);
    }

    // Fase 97: quién autoriza y desde qué monto.
    public function config(Request $request, \App\Services\Compras\AutorizacionComprasService $aut)
    {
        $d = $request->validate(['autoriza_desde' => 'required|numeric|min:0', 'autorizadores' => 'nullable|array', 'autorizadores.*' => 'integer|exists:users,id']);
        $aut->guardarConfig($request->user()->business, (float) $d['autoriza_desde'], $d['autorizadores'] ?? []);
        return back()->with('success', (float) $d['autoriza_desde'] > 0 ? 'Listo: las órdenes desde ese monto esperan autorización.' : 'Las órdenes salen sin autorización.');
    }

    public function autorizar(Request $request, int $id, \App\Services\Compras\AutorizacionComprasService $aut)
    {
        $d = $request->validate(['nota' => 'nullable|string|max:300']);
        $oc = $aut->autorizar(OrdenCompra::findOrFail($id), $request->user(), $d['nota'] ?? null);
        return back()->with('success', "Orden {$oc->numeroFormateado()} autorizada y enviada.");
    }

    public function rechazar(Request $request, int $id, \App\Services\Compras\AutorizacionComprasService $aut)
    {
        $d = $request->validate(['nota' => 'required|string|max:300']);
        $oc = $aut->rechazar(OrdenCompra::findOrFail($id), $request->user(), $d['nota']);
        return back()->with('success', "Orden {$oc->numeroFormateado()} rechazada. Quien la pidió ya lo sabe.");
    }

    public function enviar(int $id) { $oc = $this->service->enviar(OrdenCompra::findOrFail($id)); if ($oc->autorizacion === 'pendiente' && $oc->estado === 'borrador') return back()->with('success', "Orden {$oc->numeroFormateado()} enviada a autorizar: supera el monto configurado."); return back()->with('success', "Orden {$oc->numeroFormateado()} marcada como enviada."); }

    public function cancelar(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $this->service->cancelar(OrdenCompra::findOrFail($id), $request->motivo);
        return back()->with('success', 'Orden cancelada.');
    }

    // Pedido sugerido (JSON) para armar la orden.
    public function sugerir(Request $request)
    {
        $f = $request->validate(['modo' => 'required|in:ventas,faltantes', 'contact_id' => 'nullable|integer', 'rubro_id' => 'nullable|integer', 'desde' => 'nullable|date', 'hasta' => 'nullable|date', 'dias_cobertura' => 'nullable|integer|min:1|max:365']);
        return response()->json($this->service->sugerir($f));
    }

    // Lleva al formulario de compra con lo pendiente de la OC precargado.
    public function recibir(int $id)
    {
        abort_if(OrdenCompra::findOrFail($id)->autorizacion === 'pendiente', 422, 'La orden todavía espera autorización.'); // Fase 97
        $oc = OrdenCompra::with('items.product')->findOrFail($id);
        return redirect('/proveedores/compras/nueva?orden=' . $oc->id);
    }

    public function imprimir(int $id)
    {
        $oc = OrdenCompra::with(['items.product', 'contact', 'business', 'location'])->findOrFail($id);
        return view('compras.orden', ['oc' => $oc, 'b' => $oc->business]);
    }

    private function datosForm(Request $request, ?OrdenCompra $oc): array
    {
        [$productos, $prodParcial] = \App\Support\Catalogo::productos('orden', $oc ? $oc->items->pluck('product_id')->all() : []);
        [$proveedores, $provParcial] = \App\Support\Catalogo::contactos('proveedor', array_filter([$oc?->contact_id, (int) $request->input('contact_id')]));
        return [
            'orden' => $oc ? $this->resumir($oc) + ['fecha' => $oc->fecha->toDateString(), 'fecha_entrega' => $oc->fecha_entrega?->toDateString(), 'notas' => $oc->notas, 'items' => $oc->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'notas' => $i->notas])] : null,
            'contactIdInicial' => (int) $request->input('contact_id') ?: null,
            'proveedores' => $proveedores, 'productos' => $productos, 'catalogoParcial' => ['proveedores' => $provParcial, 'productos' => $prodParcial],
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    private function resumir(OrdenCompra $o): array
    {
        return ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'fecha' => $o->fecha->format('d/m/Y'), 'entrega' => $o->fecha_entrega?->format('d/m/Y'), 'proveedor' => $o->contact?->name, 'contact_id' => $o->contact_id, 'estado' => $o->estado, 'autorizacion' => $o->autorizacion, 'autorizacion_nota' => $o->autorizacion_nota, 'autorizador' => $o->autorizada_por ? $o->autorizador?->name : null, 'autorizada_en' => $o->autorizada_en?->format('d/m/Y H:i'), 'total' => (float) $o->total, 'items_count' => $o->items()->count(), 'atrasada' => in_array($o->estado, ['enviada', 'parcial'], true) && $o->fecha_entrega && $o->fecha_entrega->lt(today())];
    }
}
