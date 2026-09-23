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
            'resumen' => ['abiertas' => OrdenCompra::whereIn('estado', ['borrador', 'enviada', 'parcial'])->count(), 'monto_abierto' => (float) OrdenCompra::whereIn('estado', ['borrador', 'enviada', 'parcial'])->sum('total'), 'atrasadas' => OrdenCompra::whereIn('estado', ['enviada', 'parcial'])->whereDate('fecha_entrega', '<', today())->count()],
        ]);
    }

    public function create(Request $request) { return Inertia::render('Proveedores/OrdenForm', $this->datosForm($request, null)); }

    public function edit(Request $request, int $id)
    {
        $oc = OrdenCompra::with('items')->findOrFail($id);
        abort_if(! in_array($oc->estado, ['borrador', 'enviada'], true), 422, 'Esta orden ya no se edita.');
        return Inertia::render('Proveedores/OrdenForm', $this->datosForm($request, $oc));
    }

    public function store(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'contact_id' => 'required|exists:contacts,id', 'fecha' => 'required|date', 'fecha_entrega' => 'nullable|date', 'notas' => 'nullable|string|max:1000', 'origen' => 'nullable|in:manual,sugerido,faltantes',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|exists:products,id', 'items.*.descripcion' => 'nullable|string|max:150', 'items.*.cantidad' => 'required|numeric|min:0', 'items.*.precio_unit' => 'nullable|numeric|min:0', 'items.*.notas' => 'nullable|string|max:150',
        ]);
        $oc = $this->service->guardar($d, $id ? OrdenCompra::findOrFail($id) : null);
        if ($request->boolean('enviar') && $oc->estado === 'borrador') $this->service->enviar($oc);
        return redirect("/proveedores/ordenes/{$oc->id}")->with('success', "Orden {$oc->numeroFormateado()} " . ($request->boolean('enviar') ? 'enviada.' : 'guardada.'));
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
        ]);
    }

    public function enviar(int $id) { $oc = $this->service->enviar(OrdenCompra::findOrFail($id)); return back()->with('success', "Orden {$oc->numeroFormateado()} marcada como enviada."); }

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
        return [
            'orden' => $oc ? $this->resumir($oc) + ['fecha' => $oc->fecha->toDateString(), 'fecha_entrega' => $oc->fecha_entrega?->toDateString(), 'notas' => $oc->notas, 'items' => $oc->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'notas' => $i->notas])] : null,
            'contactIdInicial' => (int) $request->input('contact_id') ?: null,
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'cuit' => $p->cuit, 'email' => $p->email]),
            'productos' => Product::where('active', true)->whereIn('tipo', ['producto', 'insumo'])->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'precio_compra' => (float) ($p->precio_compra ?: $p->cost), 'stock' => (float) $p->stock, 'stock_min' => (float) $p->stock_min, 'proveedor_id' => $p->proveedor_id]),
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    private function resumir(OrdenCompra $o): array
    {
        return ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'fecha' => $o->fecha->format('d/m/Y'), 'entrega' => $o->fecha_entrega?->format('d/m/Y'), 'proveedor' => $o->contact?->name, 'contact_id' => $o->contact_id, 'estado' => $o->estado, 'total' => (float) $o->total, 'items_count' => $o->items()->count(), 'atrasada' => in_array($o->estado, ['enviada', 'parcial'], true) && $o->fecha_entrega && $o->fecha_entrega->lt(today())];
    }
}
