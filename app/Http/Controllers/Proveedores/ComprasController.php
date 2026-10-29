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
            ->when($request->estado, fn($q, $e) => $e === 'pendiente' ? $q->pendientesPago() : ($e === 'vencido' ? $q->pendientesPago()->where('fecha_vto', '<', today()->toDateString()) : $q->where('estado', $e)))
            ->when($request->contact_id, fn($q, $c) => $q->where('contact_id', $c))
            ->when($request->desde, fn($q, $d) => $q->where('fecha', '>=', d))
            ->when($request->hasta, fn($q, $h) => $q->where('fecha', '<=', h))
            ->when($request->buscar, fn($q, $b) => $q->where(fn($w) => $w->where('numero_proveedor', 'like', "%{$b}%")->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$b}%"))))
            ->orderByDesc('fecha')->orderByDesc('id');
        $resumen = (clone $q)->reorder()->selectRaw("COUNT(*) as cantidad, COALESCE(SUM(CASE WHEN estado='emitido' THEN total ELSE 0 END),0) as total, COALESCE(SUM(CASE WHEN estado='emitido' THEN saldo ELSE 0 END),0) as saldo")->first();

        return Inertia::render('Proveedores/Compras', [
            'lista' => $q->paginate(25)->withQueryString()->through(fn($c) => $this->resumir($c)),
            'resumen' => ['cantidad' => (int) $resumen->cantidad, 'total' => (float) $resumen->total, 'saldo' => (float) $resumen->saldo],
            'filtros' => $request->only('estado', 'contact_id', 'desde', 'hasta', 'buscar'),
            'proveedores' => Contact::suppliers()->orderBy('name')->get(['id', 'name']),
            'reclamosAbiertos' => \App\Models\ReclamoCompra::abiertos()->count(),
            'exigeControl' => (bool) (app(\App\Services\Compras\RecepcionService::class)->config($request->user()->business)['exigir_control'] ?? false),
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
            'contact_id' => 'required|integer|exists:contacts,id', 'tipo' => 'required|in:FA,FB,FC,FE,NCA,NCB,NCC,NDA,NDB,NDC,REM', 'solo_stock' => 'boolean', 'solo_contable' => 'boolean', 'remito_id' => 'nullable|integer', 'items.*.actualiza_costo' => 'nullable|boolean', 'items.*.codigo' => 'nullable|string|max:60', 'items.*.bulto' => 'nullable|integer', 'borrador_id' => 'nullable|integer', 'numero_proveedor' => 'nullable|string|max:20', 'cae_proveedor' => 'nullable|string|max:20',
            'fecha' => 'required|date', 'fecha_vto' => 'nullable|date', 'dias_vto' => 'nullable|integer|min:0|max:365', 'condicion' => 'nullable|in:contado,cta_cte', 'moneda' => 'nullable|in:ARS,USD', 'cotizacion' => 'nullable|numeric|min:0', 'origen_carga' => 'nullable|in:manual,ocr,afip_csv', 'origen_id' => 'nullable|integer', 'orden_compra_id' => 'nullable|integer|exists:ordenes_compra,id', 'notas' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|integer|exists:products,id', 'items.*.descripcion' => 'nullable|string|max:255', 'items.*.cantidad' => 'required|numeric|gt:0', 'items.*.unidad' => 'nullable|string|max:10',
            'items.*.precio_unit' => 'required|numeric|min:0', 'items.*.descuento' => 'nullable|numeric|min:0|max:100', 'items.*.alicuota_iva' => 'nullable|numeric|in:0,2.5,5,10.5,21,27', 'items.*.lote' => 'nullable|string|max:40', 'items.*.vencimiento' => 'nullable|date', 'items.*.serie' => 'nullable|string|max:500',
            'impuestos' => 'nullable|array', 'impuestos.*.tipo' => 'required_with:impuestos|string|max:30', 'impuestos.*.monto' => 'required_with:impuestos|numeric|min:0',
        ]);
        // Remito del proveedor sin precios: entra sólo al stock, como tipo REM. La factura de ese remito: sólo contable.
        if ($data['tipo'] === 'REM') { $data['solo_stock'] = true; $data['condicion'] = 'contado'; }
        if (! empty($data['remito_id'])) { $data['solo_contable'] = true; Comprobante::compras()->where('solo_stock', true)->findOrFail($data['remito_id']); }
        $c = $this->service->guardarBorrador($data, $id ? Comprobante::compras()->findOrFail($id) : null);
        // La foto con la que se leyó queda con la compra: se ve al costado mientras se controla la recepción.
        if ($request->filled('adjunto_id')) ComprobanteAdjunto::whereNull('comprobante_id')->whereKey((int) $request->input('adjunto_id'))->update(['comprobante_id' => $c->id]);
        // Lo que confirmaste queda aprendido para la próxima factura de ese proveedor; la "a medio cargar" se borra.
        app(\App\Services\Compras\LecturaCompraService::class)->aprender($c->contact, $data['items'], $data['fecha']);
        if (! empty($data['borrador_id'])) \App\Models\CompraBorrador::whereKey($data['borrador_id'])->delete();
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
            // Fase 31.5: controlar lo que llegó con la pistola; si ya se controló, cómo fue.
            'controlable' => \App\Services\Compras\RecepcionService::controlable($c),
            'recepcion' => ($r = \App\Models\Recepcion::where('comprobante_id', $c->id)->orderByDesc('id')->first()) ? ['id' => $r->id, 'estado' => $r->estado, 'cerrada_en' => $r->cerrada_en?->format('d/m/Y H:i'), 'diferencias' => $r->items()->whereRaw('contado <> esperado')->count()] : null,
            'reclamos' => \App\Models\ReclamoCompra::where('comprobante_id', $c->id)->orderByDesc('id')->get()->map(fn($x) => ['id' => $x->id, 'estado' => $x->estado, 'texto' => $x->texto, 'telefono' => preg_replace('/\D/', '', (string) ($c->contact?->mobile ?: $c->contact?->phone)), 'email' => $c->contact?->email, 'resolucion' => $x->resolucion, 'fecha' => $x->created_at->format('d/m/Y')]),
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

    public function ocr(Request $request, CompraOCRService $ocr, \App\Services\Compras\LecturaCompraService $lectura)
    {
        $request->validate(['archivo' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:12288', 'qr' => 'nullable|string|max:2000']);
        $f = $request->file('archivo');
        $res = $ocr->leer(base64_encode(file_get_contents($f->getRealPath())), $f->getMimeType());
        // Fase 31: el QR de ARCA da la cabecera exacta; después se entiende la lectura (proveedor, bultos, descuentos, pie).
        $qr = $lectura->qrAfip($request->input('qr'));
        if ($qr && ! $res['ok']) $res = ['ok' => true, 'datos' => ['proveedor' => ['cuit' => $qr['cuit']], 'items' => [], 'percepciones' => [], 'observaciones' => 'La IA no pudo leer los renglones; la cabecera salió del QR.'], 'proveedor_id' => null, 'proveedor_nuevo' => false];
        if ($res['ok']) {
            $prov = $res['proveedor_id'] ? \App\Models\Contact::find($res['proveedor_id']) : null;
            $res['datos'] = $lectura->entender($res['datos'], $prov, $qr);
            $res['proveedor_id'] = $res['datos']['proveedor_id'] ?? $res['proveedor_id'];
            $res['proveedor_nuevo'] = ! $res['proveedor_id'] && ! empty($res['datos']['proveedor']['nombre'] ?? $res['datos']['proveedor']['cuit'] ?? null);
        }
        $path = $f->store("adjuntos/{$request->user()->business_id}", 'local');
        $adj = ComprobanteAdjunto::create(['business_id' => $request->user()->business_id, 'user_id' => $request->user()->id, 'tipo' => str_contains($f->getMimeType(), 'pdf') ? 'pdf' : 'imagen', 'archivo' => $path, 'items_detectados' => $res['datos'] ?? null, 'confianza' => $res['datos']['confianza'] ?? null]);
        $res['adjunto_id'] = $adj->id;
        return response()->json($res);
    }

    // ---- Fase 31: revisar antes de mandar, el pie que se pregunta una vez, comparar con la orden, facturas a medio cargar ----
    public function revisar(Request $request, \App\Services\Compras\LecturaCompraService $lectura)
    {
        $d = $request->validate(['items' => 'required|array', 'total' => 'nullable|numeric', 'impuestos' => 'nullable|array']);
        return response()->json($lectura->revisar($d['items'], isset($d['total']) ? (float) $d['total'] : null, $d['impuestos'] ?? []));
    }

    public function reglaPie(Request $request, \App\Services\Compras\LecturaCompraService $lectura)
    {
        $d = $request->validate(['contact_id' => 'nullable|integer', 'cuit' => 'nullable|string|max:20', 'concepto' => 'required|string|max:150', 'tratamiento' => 'required|in:' . implode(',', array_keys(\App\Models\ProveedorRegla::TRATAMIENTOS))]);
        $cuit = $d['cuit'] ?? \App\Models\Contact::find($d['contact_id'] ?? 0)?->cuit;
        $lectura->reglaPie($cuit, $d['concepto'], $d['tratamiento']);
        return response()->json(['ok' => true]);
    }

    public function compararOc(Request $request, \App\Services\Compras\LecturaCompraService $lectura)
    {
        $d = $request->validate(['orden_compra_id' => 'required|integer', 'items' => 'required|array', 'tolerancia' => 'nullable|numeric|min:0|max:50']);
        $oc = \App\Models\OrdenCompra::with('items')->findOrFail($d['orden_compra_id']);
        return response()->json($lectura->compararOc($d['items'], $oc, (float) ($d['tolerancia'] ?? \App\Models\Contact::find($oc->contact_id)?->compras_config['tolerancia_oc'] ?? 2)));
    }

    // Lo abierto del proveedor: órdenes para comparar y remitos sin facturar.
    public function abiertos(int $contactId)
    {
        return response()->json([
            'ordenes' => \App\Models\OrdenCompra::where('contact_id', $contactId)->whereIn('estado', ['enviada', 'parcial', 'borrador', 'abierta'])->orderByDesc('id')->limit(20)->get()->filter(fn($o) => $o->abierta())->values()->map(fn($o) => ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'fecha' => $o->fecha?->format('d/m/Y'), 'total' => (float) $o->total]),
            'remitos' => Comprobante::compras()->where('contact_id', $contactId)->where('solo_stock', true)->where('estado', 'emitido')->where(fn($q) => $q->whereNull('notas')->orWhere('notas', 'not like', '%Facturado por%'))->orderByDesc('id')->limit(20)->get()->map(fn($r) => ['id' => $r->id, 'numero' => $r->numero_proveedor ?: "#{$r->id}", 'fecha' => $r->fecha->format('d/m/Y'), 'items' => $r->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad])]),
        ]);
    }

    public function borradores(Request $request)
    {
        return response()->json(\App\Models\CompraBorrador::orderByDesc('updated_at')->limit(30)->get()->map(fn($b) => ['id' => $b->id, 'proveedor' => $b->proveedor, 'numero' => $b->numero, 'total' => $b->total, 'cuando' => $b->updated_at->diffForHumans(), 'adjuntos' => count($b->adjuntos ?? [])]));
    }

    public function guardarBorradorLectura(Request $request, \App\Services\Compras\LecturaCompraService $lectura, ?int $id = null)
    {
        $d = $request->validate(['datos' => 'required|array']);
        $b = $lectura->guardarBorrador($request->user()->id, $request->user()->business_id, $d['datos'], $id);
        return response()->json(['ok' => true, 'id' => $b->id]);
    }

    public function borrador(int $id) { $b = \App\Models\CompraBorrador::findOrFail($id); return response()->json(['id' => $b->id, 'datos' => $b->datos, 'adjuntos' => $b->adjuntos]); }
    public function borrarBorrador(int $id) { \App\Models\CompraBorrador::findOrFail($id)->delete(); return response()->json(['ok' => true]); }

    public function importarAfip(Request $request, AfipCsvImportService $import)
    {
        $request->validate(['archivo' => 'required_without:token|file|mimes:csv,txt|max:4096', 'token' => 'nullable|string|max:80', 'solo' => 'nullable|array', 'solo.*' => 'string|max:80', 'registrar' => 'boolean']);
        // Fase 31.6: desde el cruce se crean sólo las que faltan (o las que elegiste), sin volver a subir el archivo.
        $contenido = $request->hasFile('archivo') ? file_get_contents($request->file('archivo')->getRealPath()) : \Illuminate\Support\Facades\Cache::get('afip_csv_' . $request->user()->business_id . '_' . $request->input('token'));
        abort_if($contenido === null, 422, 'El archivo ya no está: volvé a subirlo.');
        $res = $import->importar($contenido, $request->boolean('registrar'), $request->filled('solo') ? $request->input('solo') : null);
        $msg = "Importación AFIP: {$res['creadas']} compras creadas, {$res['existentes']} ya existían, {$res['proveedores_nuevos']} proveedores nuevos." . ($res['errores'] ? ' Errores: ' . implode(' | ', array_slice($res['errores'], 0, 5)) : '');
        return back()->with($res['errores'] && ! $res['creadas'] ? 'error' : 'success', $msg);
    }

    // Fase 31.6 · Qué me falta cargar: el CSV de Mis Comprobantes recibidos contra lo cargado. El archivo queda media hora para crear las que faltan.
    public function cruzarAfip(Request $request, AfipCsvImportService $import)
    {
        $request->validate(['archivo' => 'required|file|mimes:csv,txt|max:4096']);
        $contenido = file_get_contents($request->file('archivo')->getRealPath());
        $res = $import->cruzar($contenido);
        abort_if($res['filas'] === 0, 422, 'No se encontraron comprobantes en el archivo: ¿es el CSV de Mis Comprobantes → Recibidos?');
        $token = \Illuminate\Support\Str::random(24);
        \Illuminate\Support\Facades\Cache::put('afip_csv_' . $request->user()->business_id . '_' . $token, $contenido, now()->addMinutes(30));
        return response()->json($res + ['token' => $token]);
    }

    private function datosForm(Request $request, ?Comprobante $c): array
    {
        [$productos, $prodParcial] = \App\Support\Catalogo::productos('compra', $c ? $c->items->pluck('product_id')->all() : []);
        [$proveedores, $provParcial] = \App\Support\Catalogo::contactos('proveedor', array_filter([$c?->contact_id, (int) $request->input('contact_id')]));
        return [
            'compra' => $c ? array_merge($this->resumir($c), ['contact_id' => $c->contact_id, 'tipo' => $c->tipo, 'numero_proveedor' => $c->numero_proveedor, 'cae_proveedor' => $c->cae_proveedor, 'origen_id' => $c->origen_id, 'fecha' => $c->fecha->toDateString(), 'fecha_vto' => $c->fecha_vto?->toDateString(), 'condicion' => $c->condicion, 'notas' => $c->notas, 'moneda' => $c->moneda ?? 'ARS', 'cotizacion' => (float) $c->cotizacion,
                'items' => $c->items->map(fn($i) => ['product_id' => $i->product_id, 'precio_unit_me' => ($c->moneda ?? 'ARS') !== 'ARS' && (float) $c->cotizacion > 0 ? round((float) $i->precio_unit / (float) $c->cotizacion, 4) : null, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva]),
                'impuestos' => $c->impuestos->map(fn($i) => ['tipo' => $i->tipo, 'monto' => (float) $i->monto])]) : null,
            'contactIdInicial' => (int) $request->input('contact_id') ?: null,
            'proveedores' => $proveedores, 'productos' => $productos, 'catalogoParcial' => ['proveedores' => $provParcial, 'productos' => $prodParcial],
            'fceHabilitado' => true,
            'iaDisponible' => (bool) config('services.anthropic.api_key'), 'cotizacionUsd' => \App\Models\Cotizacion::valor($request->user()->business_id),
        ];
    }

    private function resumir(Comprobante $c): array
    {
        return ['id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombreTipo(), 'letra' => $c->def()['letra'], 'grupo' => $c->def()['grupo'], 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'fecha_vto' => $c->fecha_vto?->format('d/m/Y'),
            'proveedor' => $c->contact?->name, 'contact_id' => $c->contact_id, 'total' => (float) $c->total, 'saldo' => (float) $c->saldo, 'estado' => $c->estado, 'estado_pago' => $c->estadoCobro(), 'vencido' => $c->estadoCobro() === 'pendiente' && $c->fecha_vto && $c->fecha_vto->lt(today()), 'origen_carga' => $c->origen_carga, 'condicion' => $c->condicion, 'moneda' => $c->moneda ?? 'ARS', 'cotizacion' => (float) $c->cotizacion, 'total_me' => (float) $c->total_me];
    }
}
