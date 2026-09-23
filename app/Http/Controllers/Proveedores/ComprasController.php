<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\ComprobanteAdjunto;
use App\Models\Contact;
use App\Models\Product;
use App\Services\Compras\AfipCsvImportService;
use App\Services\Compras\CompraOCRService;
use App\Services\Compras\CompraService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComprasController extends Controller
{
    public function __construct(private CompraService $service) {}

    public function index(Request $request)
    {
        $q = Comprobante::compras()->with('contact:id,name')
            ->when($request->estado, fn($q, $e) => $e === 'pendiente' ? $q->pendientesPago() : ($e === 'vencido' ? $q->pendientesPago()->whereDate('fecha_vto', '<', today()) : $q->where('estado', $e)))
            ->when($request->contact_id, fn($q, $c) => $q->where('contact_id', $c))
            ->when($request->desde, fn($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($request->hasta, fn($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('numero_proveedor', 'like', "%{$b}%")->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$b}%"))))
            ->orderByDesc('fecha')->orderByDesc('id');
        $resumen = (clone $q)->selectRaw("COUNT(*) as cantidad, COALESCE(SUM(CASE WHEN estado='emitido' THEN total ELSE 0 END),0) as total, COALESCE(SUM(CASE WHEN estado='emitido' THEN saldo ELSE 0 END),0) as saldo")->first();

        return Inertia::render('Proveedores/Compras', [
            'lista' => $q->paginate(25)->withQueryString()->through(fn($c) => $this->resumir($c)),
            'resumen' => ['cantidad' => (int) $resumen->cantidad, 'total' => (float) $resumen->total, 'saldo' => (float) $resumen->saldo],
            'filtros' => $request->only('estado', 'contact_id', 'desde', 'hasta', 'buscar'),
            'proveedores' => Contact::suppliers()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request, \App\Services\Compras\OrdenCompraService $ocs)
    {
        $datos = $this->datosForm($request, null);
        if ($request->orden) {
            $oc = \App\Models\OrdenCompra::with('items.product')->findOrFail($request->orden);
            $datos['desdeOrden'] = $ocs->datosCompra($oc) + ['numero_oc' => $oc->numeroFormateado()];
        }
        return Inertia::render('Proveedores/CompraForm', $datos);
    }

    public function edit(Request $request, int $id)
    {
        $c = Comprobante::compras()->with(['items', 'impuestos'])->findOrFail($id);
        abort_if($c->estado !== 'borrador', 422, 'Solo se editan borradores.');
        return Inertia::render('Proveedores/CompraForm', $this->datosForm($request, $c));
    }

    public function store(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id', 'tipo' => 'required|in:FA,FB,FC,FE,NCA,NCB,NCC,NDA,NDB,NDC', 'numero_proveedor' => 'nullable|string|max:20', 'cae_proveedor' => 'nullable|string|max:20',
            'fecha' => 'required|date', 'fecha_vto' => 'nullable|date', 'dias_vto' => 'nullable|integer|min:0|max:365', 'condicion' => 'nullable|in:contado,cta_cte', 'origen_carga' => 'nullable|in:manual,ocr,afip_csv', 'origen_id' => 'nullable|integer', 'orden_compra_id' => 'nullable|integer|exists:ordenes_compra,id', 'notas' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|integer|exists:products,id', 'items.*.descripcion' => 'nullable|string|max:255', 'items.*.cantidad' => 'required|numeric|gt:0', 'items.*.unidad' => 'nullable|string|max:10',
            'items.*.precio_unit' => 'required|numeric|min:0', 'items.*.descuento' => 'nullable|numeric|min:0|max:100', 'items.*.alicuota_iva' => 'nullable|numeric|in:0,2.5,5,10.5,21,27',
            'impuestos' => 'nullable|array', 'impuestos.*.tipo' => 'required_with:impuestos|string|max:30', 'impuestos.*.monto' => 'required_with:impuestos|numeric|min:0',
        ]);
        $c = $this->service->guardarBorrador($data, $id ? Comprobante::compras()->findOrFail($id) : null);
        if ($request->boolean('registrar')) {
            $c = $this->service->registrar($c);
            return redirect("/proveedores/compras/{$c->id}")->with('success', "Compra {$c->nombreTipo()} {$c->numeroFormateado()} registrada.");
        }
        return redirect("/proveedores/compras/{$c->id}")->with('success', 'Borrador guardado.');
    }

    public function show(int $id)
    {
        $c = Comprobante::compras()->with(['items.product:id,name,sku', 'impuestos', 'contact', 'origen', 'derivados', 'pagosImputados.pago', 'location:id,name', 'user:id,name'])->findOrFail($id);
        return Inertia::render('Proveedores/CompraVer', [
            'c' => array_merge($this->resumir($c), [
                'items' => $c->items->map(fn($i) => ['id' => $i->id, 'sku' => $i->product?->sku, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva, 'total' => (float) $i->total, 'product_id' => $i->product_id]),
                'impuestos' => $c->impuestos->map(fn($i) => ['tipo' => $i->tipo, 'monto' => (float) $i->monto]),
                'proveedor' => $c->contact ? ['id' => $c->contact->id, 'name' => $c->contact->name, 'cuit' => $c->contact->cuit, 'condicion_iva' => $c->contact->condicion_iva, 'balance' => (float) $c->contact->balance] : null,
                'origen' => $c->origen ? ['id' => $c->origen->id, 'nombre' => $c->origen->nombreTipo(), 'numero' => $c->origen->numeroFormateado()] : null,
                'pagos' => $c->pagosImputados->map(fn($i) => ['pago_id' => $i->pago_id, 'numero' => $i->pago?->numeroFormateado(), 'fecha' => $i->pago?->fecha->format('d/m/Y'), 'monto' => (float) $i->monto, 'estado' => $i->pago?->estado]),
                'sucursal' => $c->location?->name, 'usuario' => $c->user?->name, 'notas' => $c->notas, 'neto' => (float) $c->neto, 'iva' => (float) $c->iva, 'percepciones' => (float) $c->percepciones, 'cae' => $c->cae_proveedor,
            ]),
            'puedeNC' => $c->estado === 'emitido' && $c->esFactura(),
        ]);
    }

    public function registrar(int $id)
    {
        $c = $this->service->registrar(Comprobante::compras()->findOrFail($id));
        return back()->with('success', "Compra {$c->numeroFormateado()} registrada.");
    }

    public function anular(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $this->service->anular(Comprobante::compras()->findOrFail($id), $request->motivo);
        return back()->with('success', 'Compra anulada.');
    }

    public function notaCredito(int $id)
    {
        $o = Comprobante::compras()->with('items')->findOrFail($id);
        abort_if(! $o->esFactura() || $o->estado !== 'emitido', 422, 'Solo desde facturas registradas.');
        $nc = $this->service->guardarBorrador([
            'contact_id' => $o->contact_id, 'tipo' => 'NC' . $o->def()['letra'], 'fecha' => today()->toDateString(), 'origen_id' => $o->id, 'condicion' => $o->condicion, 'notas' => "NC sobre {$o->numeroFormateado()}",
            'items' => $o->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => $i->precio_unit, 'descuento' => $i->descuento, 'alicuota_iva' => $i->alicuota_iva])->all(),
        ]);
        return redirect("/proveedores/compras/{$nc->id}/editar")->with('success', 'Borrador de nota de crédito creado: cargá el número del proveedor y registralo.');
    }

    public function ocr(Request $request, CompraOCRService $ocr)
    {
        $request->validate(['archivo' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:12288']);
        $f = $request->file('archivo');
        $res = $ocr->leer(base64_encode(file_get_contents($f->getRealPath())), $f->getMimeType());
        $path = $f->store("adjuntos/{$request->user()->business_id}", 'local');
        ComprobanteAdjunto::create(['business_id' => $request->user()->business_id, 'user_id' => $request->user()->id, 'tipo' => str_contains($f->getMimeType(), 'pdf') ? 'pdf' : 'imagen', 'archivo' => $path, 'items_detectados' => $res['datos'] ?? null, 'confianza' => $res['datos']['confianza'] ?? null]);
        return response()->json($res);
    }

    public function importarAfip(Request $request, AfipCsvImportService $import)
    {
        $request->validate(['archivo' => 'required|file|mimes:csv,txt|max:4096', 'registrar' => 'boolean']);
        $res = $import->importar(file_get_contents($request->file('archivo')->getRealPath()), $request->boolean('registrar'));
        $msg = "Importación AFIP: {$res['creadas']} compras creadas, {$res['existentes']} ya existían, {$res['proveedores_nuevos']} proveedores nuevos." . ($res['errores'] ? ' Errores: ' . implode(' | ', array_slice($res['errores'], 0, 5)) : '');
        return back()->with($res['errores'] && ! $res['creadas'] ? 'error' : 'success', $msg);
    }

    private function datosForm(Request $request, ?Comprobante $c): array
    {
        return [
            'compra' => $c ? array_merge($this->resumir($c), ['contact_id' => $c->contact_id, 'tipo' => $c->tipo, 'numero_proveedor' => $c->numero_proveedor, 'cae_proveedor' => $c->cae_proveedor, 'origen_id' => $c->origen_id, 'fecha' => $c->fecha->toDateString(), 'fecha_vto' => $c->fecha_vto?->toDateString(), 'condicion' => $c->condicion, 'notas' => $c->notas,
                'items' => $c->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva]),
                'impuestos' => $c->impuestos->map(fn($i) => ['tipo' => $i->tipo, 'monto' => (float) $i->monto])]) : null,
            'contactIdInicial' => (int) $request->input('contact_id') ?: null,
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'cuit' => $p->cuit, 'condicion_iva' => $p->condicion_iva, 'dias_pago' => $p->dias_pago, 'balance' => (float) $p->balance]),
            'productos' => Product::where('active', true)->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'iva' => (float) $p->iva, 'cost' => (float) $p->cost, 'stock' => (float) $p->stock]),
            'iaDisponible' => (bool) config('services.anthropic.api_key'),
        ];
    }

    private function resumir(Comprobante $c): array
    {
        return ['id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombreTipo(), 'letra' => $c->def()['letra'], 'grupo' => $c->def()['grupo'], 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'fecha_vto' => $c->fecha_vto?->format('d/m/Y'),
            'proveedor' => $c->contact?->name, 'contact_id' => $c->contact_id, 'total' => (float) $c->total, 'saldo' => (float) $c->saldo, 'estado' => $c->estado, 'estado_pago' => $c->estadoCobro(), 'vencido' => $c->estadoCobro() === 'pendiente' && $c->fecha_vto && $c->fecha_vto->lt(today()), 'origen_carga' => $c->origen_carga, 'condicion' => $c->condicion];
    }
}
