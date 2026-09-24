<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\LoteFacturacion;
use App\Models\Product;
use App\Models\PuntoVenta;
use App\Services\Comprobantes\AfipEmisor;
use App\Services\Comprobantes\ComprobanteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ComprobantesController extends Controller
{
    public function __construct(private ComprobanteService $service, private AfipEmisor $afip) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $grupo = $request->input('grupo', 'todos');
        $tiposGrupo = collect(Comprobante::TIPOS)->filter(fn($d) => $grupo === 'todos' || $d['grupo'] === $grupo)->keys()->all();

        $q = Comprobante::ventas()->with('contact:id,name')
            ->whereIn('tipo', $tiposGrupo)
            ->when($request->estado, fn($q, $e) => $e === 'pendiente' ? $q->pendientesCobro() : ($e === 'vencido' ? $q->pendientesCobro()->where('fecha_vto', '<', today()->toDateString()) : $q->where('estado', $e)))
            ->when($request->contact_id, fn($q, $c) => $q->where('contact_id', $c))
            ->when($request->desde, fn($q, $d) => $q->where('fecha', '>=', d))
            ->when($request->hasta, fn($q, $h) => $q->where('fecha', '<=', h))
            ->when($request->sucursal === 'actual', fn($q) => $q->deSucursal($user->current_location_id))
            ->when($request->buscar, function ($q, $b) {
                $q->where(function ($w) use ($b) {
                    $w->where('numero', (int) preg_replace('/\D/', '', $b) ?: -1)
                      ->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$b}%"))
                      ->orWhere('notas', 'like', "%{$b}%");
                });
            })
            ->orderByDesc('fecha')->orderByDesc('id');

        $resumen = (clone $q)->reorder()->selectRaw("COUNT(*) as cantidad, COALESCE(SUM(CASE WHEN estado='emitido' THEN total ELSE 0 END),0) as total, COALESCE(SUM(CASE WHEN estado='emitido' THEN saldo ELSE 0 END),0) as saldo")->first();

        $lista = $q->paginate(25)->withQueryString()->through(fn($c) => $this->resumir($c));

        return Inertia::render('Comprobantes/Index', [
            'lista'    => $lista,
            'resumen'  => ['cantidad' => (int) $resumen->cantidad, 'total' => (float) $resumen->total, 'saldo' => (float) $resumen->saldo],
            'filtros'  => $request->only('grupo', 'estado', 'contact_id', 'desde', 'hasta', 'buscar', 'sucursal'),
            'grupos'   => [['key' => 'todos', 'label' => 'Todos'], ['key' => 'factura', 'label' => 'Facturas'], ['key' => 'nc', 'label' => 'Notas de crédito'], ['key' => 'nd', 'label' => 'Notas de débito'], ['key' => 'remito', 'label' => 'Remitos'], ['key' => 'presupuesto', 'label' => 'Presupuestos']],
            'clientes' => Contact::customers()->orderBy('name')->get(['id', 'name']),
            'afipConfigurado' => $this->afip->configurado($user->business),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Comprobantes/Form', $this->datosForm($request, null));
    }

    public function edit(Request $request, int $id)
    {
        $c = Comprobante::ventas()->with(['items', 'contact'])->findOrFail($id);
        abort_if($c->estado !== 'borrador', 422, 'Solo se editan borradores.');
        return Inertia::render('Comprobantes/Form', $this->datosForm($request, $c));
    }

    public function store(Request $request, ?int $id = null)
    {
        $data = $this->validar($request);
        $c = $id ? Comprobante::ventas()->findOrFail($id) : null;
        $c = $this->service->guardarBorrador($data, $c);

        if ($request->boolean('emitir')) {
            $c = $this->service->emitir($c);
            // Canje de puntos: la línea de descuento ya viene en los ítems; acá se descuentan los puntos del cliente.
            if ((float) ($data['canje_puntos'] ?? 0) > 0 && $c->contact) app(\App\Services\Ventas\FidelizacionService::class)->canjear($c->contact, (float) $data['canje_puntos'], "Canje en {$c->nombreTipo()} {$c->numeroFormateado()}");
            $msg = "{$c->nombreTipo()} {$c->numeroFormateado()} emitido" . ($c->afip_estado === 'simulado' ? ' (simulado, sin CAE: configurá AFIP para emitir de verdad).' : ($c->sin_arca ? ' (interno, no informado a ARCA).' : ($c->manual ? ' (manual de talonario, no informado a ARCA).' : '.')));
            return redirect("/comprobantes/{$c->id}")->with('success', $msg);
        }
        return redirect("/comprobantes/{$c->id}")->with('success', 'Borrador guardado.');
    }

    public function show(int $id)
    {
        $c = Comprobante::ventas()->with(['items.product:id,name,sku', 'contact', 'origen', 'derivados', 'imputaciones.cobro', 'acopio.items', 'location:id,name', 'user:id,name', 'impuestos'])->findOrFail($id);
        $tipos = Comprobante::TIPOS;
        $letra = $c->def()['letra'];

        return Inertia::render('Comprobantes/Ver', [
            'c' => array_merge($this->resumir($c), [
                'items' => $c->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'sku' => $i->product?->sku, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva, 'neto' => (float) $i->neto, 'iva' => (float) $i->iva, 'total' => (float) $i->total, 'entregada' => (float) $i->cantidad_entregada, 'facturada' => (float) $i->cantidad_facturada]),
                'entrega_pendiente' => $c->entrega_pendiente, 'pendiente_entrega' => $c->pendienteEntrega(), 'pendiente_facturar' => $c->pendienteFacturar(), 'fce' => $c->fce, 'fce_estado' => $c->fce_estado, 'fce_vto_pago' => $c->fce_vto_pago?->format('d/m/Y'),
                'url_publica' => $c->estado === 'emitido' ? $c->urlPublica() : null, 'link_pago' => $c->link_pago, 'link_pago_simulado' => $c->link_pago_id === 'simulado',
                'aprobado_en' => $c->aprobado_en?->format('d/m/Y H:i'), 'rechazado_en' => $c->rechazado_en?->format('d/m/Y H:i'), 'respuesta_cliente' => $c->respuesta_cliente,
                'envios' => $c->envios()->latest()->limit(5)->get()->map(fn($e) => ['canal' => $e->canal, 'destino' => $e->destino, 'estado' => $e->estado, 'fecha' => $e->created_at->format('d/m H:i')]),
                'email' => $c->contact?->email, 'telefono' => $c->contact?->mobile ?: $c->contact?->phone,
                'contacto' => $c->contact ? ['id' => $c->contact->id, 'name' => $c->contact->name, 'cuit' => $c->contact->cuit, 'condicion_iva' => $c->contact->condicion_iva, 'address' => $c->contact->address, 'city' => $c->contact->city, 'balance' => (float) $c->contact->balance] : null,
                'origen' => $c->origen ? ['id' => $c->origen->id, 'nombre' => $c->origen->nombreTipo(), 'numero' => $c->origen->numeroFormateado()] : null,
                'derivados' => $c->derivados->map(fn($d) => ['id' => $d->id, 'nombre' => $d->nombreTipo(), 'numero' => $d->numeroFormateado(), 'estado' => $d->estado]),
                'cobros' => $c->imputaciones->map(fn($i) => ['cobro_id' => $i->cobro_id, 'numero' => $i->cobro?->numeroFormateado(), 'fecha' => $i->cobro?->fecha->format('d/m/Y'), 'monto' => (float) $i->monto, 'estado' => $i->cobro?->estado]),
                'acopio' => $c->acopio ? ['id' => $c->acopio->id, 'estado' => $c->acopio->estado, 'fecha_limite' => $c->acopio->fecha_limite?->format('d/m/Y'), 'items' => $c->acopio->items->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'facturada' => (float) $i->cantidad_facturada, 'retirada' => (float) $i->cantidad_retirada, 'pendiente' => $i->pendiente()])] : null,
                'sucursal' => $c->location?->name, 'usuario' => $c->user?->name, 'notas' => $c->notas, 'afip_respuesta' => $c->afip_respuesta,
                'neto' => (float) $c->neto, 'iva' => (float) $c->iva, 'percepciones' => (float) $c->percepciones,
                'impuestos' => $c->impuestos->map(fn($t) => ['tipo' => $t->tipo, 'nombre' => \App\Models\ComprobanteImpuesto::descripcion($t->tipo), 'alicuota' => (float) $t->alicuota, 'monto' => (float) $t->monto]),
                'exportacion' => $c->esExportacion() ? ($c->exportacion ?? []) + ['pais_nombre' => config('arca_paises.paises.' . ($c->exportacion['pais'] ?? $c->contact?->pais_codigo ?? ''), $c->contact?->pais_codigo)] : null,
                'arba_ws' => app(\App\Services\Fiscal\CotService::class)->configurado($c->business),
                'transporte' => $c->tipo === 'REM' ? ['transportista' => $c->transportista, 'transportista_cuit' => $c->transportista_cuit, 'patente' => $c->patente, 'bultos' => $c->bultos, 'peso_kg' => $c->peso_kg !== null ? (float) $c->peso_kg : null, 'domicilio_entrega' => $c->domicilio_entrega, 'cot' => $c->cot] : null,
            ]),
            'conversiones' => $this->conversionesPosibles($c),
            'puedeAnular' => $c->estado !== 'anulado' && ! ($c->estado === 'emitido' && $c->esFiscal() && $c->afip_estado === 'aprobado'),
        ]);
    }

    public function emitir(int $id)
    {
        $c = $this->service->emitir(Comprobante::ventas()->findOrFail($id));
        $msg = "{$c->nombreTipo()} {$c->numeroFormateado()} emitido" . ($c->afip_estado === 'simulado' ? ' (simulado, sin CAE).' : '.');
        return back()->with('success', $msg);
    }

    public function anular(Request $request, int $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $this->service->anular(Comprobante::ventas()->findOrFail($id), $request->motivo);
        return back()->with('success', 'Comprobante anulado.');
    }

    public function convertir(Request $request, int $id)
    {
        $request->validate(['tipo' => 'required|string|max:4', 'es_acopio' => 'boolean', 'condicion' => 'nullable|in:contado,cta_cte']);
        $origen = Comprobante::ventas()->findOrFail($id);
        abort_unless(in_array($request->tipo, array_column($this->conversionesPosibles($origen), 'tipo'), true), 422, 'Conversión no permitida.');
        $nuevo = $this->service->convertir($origen, $request->tipo, $request->only('es_acopio', 'condicion'));
        return redirect("/comprobantes/{$nuevo->id}/editar")->with('success', "Borrador de {$nuevo->nombreTipo()} creado desde {$origen->numeroFormateado()}. Revisalo y emitilo.");
    }

    // Ítems de la última factura emitida a un cliente, para repetirla con un clic.
    public function ultimaDe(int $contact)
    {
        $c = Comprobante::ventas()->emitidos()->facturas()->where('contact_id', $contact)->with('items.product:id,name,unit,tipo')->orderByDesc('fecha')->orderByDesc('id')->first();
        if (! $c) return response()->json(['items' => [], 'comprobante' => null]);
        return response()->json(['comprobante' => ['numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y')], 'items' => $c->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva])->values()]);
    }

    // Contingencia ARCA: reintentar el CAE a mano y verificar un comprobante contra ARCA.
    public function reintentarCae(int $id)
    {
        $c = Comprobante::ventas()->findOrFail($id);
        $r = $this->service->reintentarCae($c);
        return match ($r['estado']) {
            'aprobado' => back()->with('success', "ARCA autorizó el comprobante: {$c->fresh()->numeroFormateado()} · CAE {$r['cae']}."),
            'rechazado' => back()->with('error', 'ARCA lo rechazó: ' . ($r['explicacion']['que'] ?? '') . ' ' . ($r['explicacion']['como'] ?? '') . ' (' . $r['error'] . ')'),
            default => back()->with('error', 'ARCA sigue sin responder. Se vuelve a intentar solo cada 5 minutos.'),
        };
    }

    public function verificarArca(int $id)
    {
        $c = Comprobante::ventas()->findOrFail($id);
        return response()->json($this->service->verificarEnArca($c));
    }

    // Remito electrónico: archivo para pedir el COT en ARBA y guardado del código devuelto.
    public function cot(int $id, \App\Services\Fiscal\CotService $cot)
    {
        $c = Comprobante::ventas()->with(['items.product', 'contact', 'business'])->findOrFail($id);
        return response($cot->archivo($c), 200, ['Content-Type' => 'text/plain; charset=ISO-8859-1', 'Content-Disposition' => 'attachment; filename="' . $cot->nombreArchivo($c) . '"']);
    }

    public function pedirCot(int $id, \App\Services\Fiscal\CotService $cot)
    {
        $c = Comprobante::ventas()->with(['items.product', 'contact', 'business'])->findOrFail($id);
        try { $r = $cot->pedir($c); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
        catch (\Illuminate\Http\Client\ConnectionException $e) { return back()->with('error', 'No se pudo conectar con ARBA. Probá de nuevo o bajá el archivo y subilo en la web.'); }
        return back()->with('success', "ARBA devolvió el COT {$r['cot']}. Ya sale impreso en el remito.");
    }

    public function guardarCot(int $id, Request $request)
    {
        $d = $request->validate(['cot' => 'nullable|string|max:40']);
        $c = Comprobante::ventas()->where('tipo', 'REM')->findOrFail($id);
        $c->forceFill(['cot' => $d['cot'] ? trim($d['cot']) : null])->save();
        \App\Models\AuditLog::registrar('editar', $c, 'COT del remito ' . $c->numeroFormateado() . ': ' . ($c->cot ?: 'borrado'));
        return back()->with('success', $c->cot ? 'COT guardado en el remito.' : 'COT borrado.');
    }

    public function imprimir(int $id)
    {
        $c = Comprobante::ventas()->with(['items', 'contact', 'business', 'location', 'impuestos'])->findOrFail($id);
        return view('comprobantes.imprimir', ['c' => $c, 'b' => $c->emisor()]);
    }

    public function lote(Request $request)
    {
        $origen = $request->input('origen', 'presupuestos');
        $tipo = $origen === 'remitos' ? 'REM' : 'PRE';
        $pendientes = Comprobante::ventas()->emitidos()->where('tipo', $tipo)->whereDoesntHave('derivados', fn($q) => $q->facturas()->where('estado', '!=', 'anulado'))
            ->with('contact:id,name,condicion_iva')->orderBy('contact_id')->orderBy('fecha')->get()
            ->map(fn($c) => $this->resumir($c));

        return Inertia::render('Comprobantes/Lote', [
            'origen' => $origen, 'pendientes' => $pendientes,
            'ultimos' => LoteFacturacion::latest()->limit(10)->get()->map(fn($l) => ['id' => $l->id, 'fecha' => $l->fecha->format('d/m/Y'), 'origen' => $l->origen, 'cantidad' => $l->cantidad, 'emitidos' => $l->emitidos, 'con_error' => $l->con_error, 'detalle' => $l->detalle]),
        ]);
    }

    public function facturarLote(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer', 'origen' => 'required|in:presupuestos,remitos', 'condicion' => 'nullable|in:contado,cta_cte']);
        $user = $request->user();
        $detalle = []; $ok = 0; $err = 0;

        foreach ($data['ids'] as $id) {
            $origen = Comprobante::ventas()->emitidos()->find($id);
            if (! $origen) { continue; }
            try {
                $f = DB::transaction(function () use ($origen, $data) {
                    $b = $this->service->convertir($origen, 'FX', ['condicion' => $data['condicion'] ?? $origen->condicion]);
                    return $this->service->emitir($b);
                });
                $ok++;
                $detalle[] = ['origen' => $origen->numeroFormateado(), 'cliente' => $origen->contact?->name, 'resultado' => $f->numeroFormateado(), 'id' => $f->id, 'ok' => true];
            } catch (\Throwable $e) {
                $err++;
                $detalle[] = ['origen' => $origen->numeroFormateado(), 'cliente' => $origen->contact?->name, 'resultado' => $e instanceof \Illuminate\Validation\ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage(), 'ok' => false];
            }
        }
        LoteFacturacion::create(['business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id, 'fecha' => today(), 'origen' => $data['origen'], 'cantidad' => count($data['ids']), 'emitidos' => $ok, 'con_error' => $err, 'detalle' => $detalle]);
        return back()->with($err ? 'error' : 'success', "Lote procesado: {$ok} facturas emitidas" . ($err ? ", {$err} con error." : '.'));
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'tipo'            => 'required|string|max:4',
            'contact_id'      => 'nullable|integer|exists:contacts,id',
            'punto_venta_id'  => 'nullable|integer|exists:puntos_venta,id',
            'vendedor_id'     => 'nullable|integer|exists:vendedores,id',
            'origen_id'       => 'nullable|integer|exists:comprobantes,id',
            'fecha'           => 'required|date',
            'condicion'       => 'required|in:contado,cta_cte',
            'dias_vto'        => 'nullable|integer|min:0|max:365',
            'es_acopio'       => 'boolean',
            'entrega_pendiente' => 'boolean',
            'fce'             => 'boolean',
            'sin_arca'        => 'boolean',
            // Manual de talonario (Fase 25.2).
            'manual'          => 'boolean', 'pv_manual' => 'nullable|required_if:manual,true|integer|min:1|max:99999', 'numero_manual' => 'nullable|required_if:manual,true|integer|min:1|max:99999999',
            'cai'             => 'nullable|string|max:20', 'cai_vto' => 'nullable|date',
            'fce_vto_pago'    => 'nullable|date',
            'notas'           => 'nullable|string|max:2000',
            'moneda'          => 'nullable|in:ARS,USD',
            'exportacion'     => 'nullable|array', 'exportacion.tipo_expo' => 'nullable|integer|in:1,2,4', 'exportacion.incoterm' => 'nullable|string|max:3', 'exportacion.permiso_embarque' => 'nullable|string|max:40', 'exportacion.pais' => 'nullable|string|max:4', 'exportacion.moneda_arca' => 'nullable|string|max:3', 'exportacion.forma_pago' => 'nullable|string|max:50', 'exportacion.obs_comerciales' => 'nullable|string|max:1000',
            'cotizacion'      => 'nullable|numeric|min:0',
            'proyecto_id'     => 'nullable|integer|exists:proyectos,id',
            // Remito: datos de transporte (para el COT de ARBA y el pie del remito).
            'transportista'      => 'nullable|string|max:120',
            'transportista_cuit' => 'nullable|string|max:13',
            'patente'            => 'nullable|string|max:12',
            'bultos'             => 'nullable|integer|min:0',
            'peso_kg'            => 'nullable|numeric|min:0',
            'domicilio_entrega'  => 'nullable|string|max:200',
            'items'           => 'required|array|min:1',
            'items.*.product_id'   => 'nullable|integer|exists:products,id',
            'items.*.descripcion'  => 'nullable|string|max:255',
            'items.*.cantidad'     => 'required|numeric|gt:0',
            'items.*.unidad'       => 'nullable|string|max:10',
            'items.*.precio_unit'  => 'required|numeric',
            'canje_puntos'         => 'nullable|numeric|min:0',
            'items.*.descuento'    => 'nullable|numeric|min:0|max:100',
            'items.*.alicuota_iva' => 'nullable|numeric|in:0,2.5,5,10.5,21,27',
        ]);
    }

    private function datosForm(Request $request, ?Comprobante $c): array
    {
        $user = $request->user();
        $origen = $request->origen_id ? Comprobante::ventas()->with('items')->find($request->origen_id) : null;
        $idsProd = array_merge($c ? $c->items->pluck('product_id')->all() : [], $origen ? $origen->items->pluck('product_id')->all() : []);
        [$productos, $productosParcial] = \App\Support\Catalogo::productos('venta', $idsProd);
        [$clientes, $clientesParcial] = \App\Support\Catalogo::contactos('cliente', array_filter([$c?->contact_id, $origen?->contact_id, (int) $request->input('contact_id')]));
        return [
            'puedeManual' => $request->user()->puede('comprobantes', 'anular'),
            'comprobante' => $c ? array_merge($this->resumir($c), [
                'contact_id' => $c->contact_id, 'punto_venta_id' => $c->punto_venta_id, 'vendedor_id' => $c->vendedor_id, 'origen_id' => $c->origen_id, 'condicion' => $c->condicion, 'es_acopio' => $c->es_acopio, 'entrega_pendiente' => $c->entrega_pendiente, 'fce' => $c->fce, 'sin_arca' => $c->sin_arca, 'exportacion' => $c->exportacion, 'fce_vto_pago' => $c->fce_vto_pago?->toDateString(), 'notas' => $c->notas, 'moneda' => $c->moneda, 'cotizacion' => (float) $c->cotizacion, 'proyecto_id' => $c->proyecto_id,
                'transportista' => $c->transportista, 'transportista_cuit' => $c->transportista_cuit, 'patente' => $c->patente, 'bultos' => $c->bultos, 'peso_kg' => $c->peso_kg !== null ? (float) $c->peso_kg : null, 'domicilio_entrega' => $c->domicilio_entrega,
                'fecha' => $c->fecha->toDateString(),
                'items' => $c->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva]),
            ]) : null,
            'vendedores' => \App\Models\Vendedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'user_id']),
            'cbuFce' => $user->business->cbu_fce,
            'proyectos' => \App\Models\Proyecto::whereNotIn('estado', ['terminado', 'cancelado'])->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'contact_id']),
            'cotizacionUsd' => \App\Models\Cotizacion::valor($user->business_id),
            'vendedorDefault' => \App\Models\Vendedor::deUsuario($user->id)?->id,
            'tipoInicial' => $c ? preg_replace('/^(F|NC|ND)[ABCE]$/', '$1X', $c->tipo) : $request->input('tipo', 'FX'),
            'origen' => $origen ? ['id' => $origen->id, 'nombre' => $origen->nombreTipo(), 'numero' => $origen->numeroFormateado(), 'contact_id' => $origen->contact_id, 'items' => $origen->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva])] : null,
            'tipos' => [
                ['key' => 'FX', 'label' => 'Factura (A/B/C según cliente)'], ['key' => 'PRE', 'label' => 'Presupuesto'], ['key' => 'REM', 'label' => 'Remito'],
                ['key' => 'NCX', 'label' => 'Nota de crédito'], ['key' => 'NDX', 'label' => 'Nota de débito'],
            ],
            'clientes' => $clientes, 'productos' => $productos, 'catalogoParcial' => ['clientes' => $clientesParcial, 'productos' => $productosParcial], 'arcaPaises' => ['incoterms' => config('arca_paises.incoterms'), 'tipos_expo' => config('arca_paises.tipos_expo'), 'monedas' => config('arca_paises.monedas'), 'paises' => config('arca_paises.paises')],
            'puntosVenta' => PuntoVenta::where('activo', true)->with('location:id,name')->orderBy('numero')->get()->map(fn($p) => ['id' => $p->id, 'numero' => $p->numero, 'sucursal' => $p->location?->name, 'modo' => $p->modo]),
            'puntoVentaDefault' => $this->service->puntoVentaPorDefecto($user)?->id,
            'empresa' => ['condicion_iva' => $user->business->condicion_iva ?? 'Responsable Inscripto'],
            'afipConfigurado' => $this->afip->configurado($user->business),
        ];
    }

    private function resumir(Comprobante $c): array
    {
        return [
            'id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombreTipo(), 'letra' => $c->def()['letra'], 'grupo' => $c->def()['grupo'],
            'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'fecha_vto' => $c->fecha_vto?->format('d/m/Y'),
            'cliente' => $c->contact?->name, 'contact_id' => $c->contact_id, 'total' => (float) $c->total, 'saldo' => (float) $c->saldo, 'moneda' => $c->moneda, 'cotizacion' => (float) $c->cotizacion, 'total_me' => (float) $c->total_me, 'proyecto' => $c->proyecto_id ? ['id' => $c->proyecto_id, 'codigo' => $c->proyecto?->codigo, 'nombre' => $c->proyecto?->nombre] : null,
            'estado' => $c->estado, 'estado_cobro' => $c->estadoCobro(), 'vencido' => $c->vencido(), 'afip_estado' => $c->afip_estado,
            'cae' => $c->cae, 'cae_vto' => $c->cae_vto?->format('d/m/Y'), 'afip_error' => $c->afip_estado === 'pendiente' ? (($c->afip_respuesta['explicacion']['que'] ?? null) ? ($c->afip_respuesta['explicacion']['que'] . ' ' . ($c->afip_respuesta['explicacion']['como'] ?? '')) : ($c->afip_respuesta['error'] ?? null)) : null, 'es_acopio' => $c->es_acopio, 'condicion' => $c->condicion, 'fiscal' => $c->esFiscal() && ! $c->sin_arca, 'interno' => (bool) $c->sin_arca, 'manual' => (bool) $c->manual, 'cai' => $c->cai, 'pv_manual' => $c->manual ? $c->punto_venta : null, 'numero_manual' => $c->manual ? $c->numero : null, 'cai_vto_iso' => $c->cai_vto?->toDateString(), 'cai_vto' => $c->cai_vto?->format('d/m/Y'),
        ];
    }

    public function linkPago(int $id, \App\Services\Ventas\LinkPagoService $links)
    {
        $c = Comprobante::ventas()->findOrFail($id);
        $link = $links->crear($c);
        return back()->with('success', $c->link_pago_id === 'simulado' ? 'Link de pago simulado creado (sin credenciales de MercadoPago).' : 'Link de pago de MercadoPago creado.');
    }

    private function conversionesPosibles(Comprobante $c): array
    {
        if ($c->estado !== 'emitido') {
            return [];
        }
        return match ($c->def()['grupo']) {
            'presupuesto' => [['tipo' => 'FX', 'label' => 'Facturar'], ['tipo' => 'REM', 'label' => 'Hacer remito']],
            'remito'      => $c->pendienteFacturar() > 0 ? [['tipo' => 'FX', 'label' => $c->pendienteFacturar() < $c->items->sum('cantidad') ? 'Facturar lo pendiente' : 'Facturar', 'parcial' => true]] : [],
            'factura'     => [['tipo' => 'NCX', 'label' => 'Nota de crédito'], ['tipo' => 'NDX', 'label' => 'Nota de débito'], ...($c->entrega_pendiente ? ($c->pendienteEntrega() > 0 ? [['tipo' => 'REM', 'label' => 'Entregar (remito)', 'parcial' => true]] : []) : (! $c->es_acopio && ! $c->derivados->contains(fn($d) => $d->tipo === 'REM') ? [['tipo' => 'REM', 'label' => 'Hacer remito']] : []))],
            default       => [],
        };
    }
}
