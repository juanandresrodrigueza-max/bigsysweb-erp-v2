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
            ->when($request->estado, fn($q, $e) => $e === 'pendiente' ? $q->pendientesCobro() : ($e === 'vencido' ? $q->pendientesCobro()->whereDate('fecha_vto', '<', today()) : $q->where('estado', $e)))
            ->when($request->contact_id, fn($q, $c) => $q->where('contact_id', $c))
            ->when($request->desde, fn($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($request->hasta, fn($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->when($request->sucursal === 'actual', fn($q) => $q->deSucursal($user->current_location_id))
            ->when($request->buscar, function ($q, $b) {
                $q->where(function ($w) use ($b) {
                    $w->where('numero', (int) preg_replace('/\D/', '', $b) ?: -1)
                      ->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$b}%"))
                      ->orWhere('notas', 'like', "%{$b}%");
                });
            })
            ->orderByDesc('fecha')->orderByDesc('id');

        $resumen = (clone $q)->selectRaw("COUNT(*) as cantidad, COALESCE(SUM(CASE WHEN estado='emitido' THEN total ELSE 0 END),0) as total, COALESCE(SUM(CASE WHEN estado='emitido' THEN saldo ELSE 0 END),0) as saldo")->first();

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
            $msg = "{$c->nombreTipo()} {$c->numeroFormateado()} emitido" . ($c->afip_estado === 'simulado' ? ' (simulado, sin CAE: configurá AFIP para emitir de verdad).' : '.');
            return redirect("/comprobantes/{$c->id}")->with('success', $msg);
        }
        return redirect("/comprobantes/{$c->id}")->with('success', 'Borrador guardado.');
    }

    public function show(int $id)
    {
        $c = Comprobante::ventas()->with(['items.product:id,name,sku', 'contact', 'origen', 'derivados', 'imputaciones.cobro', 'acopio.items', 'location:id,name', 'user:id,name'])->findOrFail($id);
        $tipos = Comprobante::TIPOS;
        $letra = $c->def()['letra'];

        return Inertia::render('Comprobantes/Ver', [
            'c' => array_merge($this->resumir($c), [
                'items' => $c->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'sku' => $i->product?->sku, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva, 'neto' => (float) $i->neto, 'iva' => (float) $i->iva, 'total' => (float) $i->total]),
                'contacto' => $c->contact ? ['id' => $c->contact->id, 'name' => $c->contact->name, 'cuit' => $c->contact->cuit, 'condicion_iva' => $c->contact->condicion_iva, 'address' => $c->contact->address, 'city' => $c->contact->city, 'balance' => (float) $c->contact->balance] : null,
                'origen' => $c->origen ? ['id' => $c->origen->id, 'nombre' => $c->origen->nombreTipo(), 'numero' => $c->origen->numeroFormateado()] : null,
                'derivados' => $c->derivados->map(fn($d) => ['id' => $d->id, 'nombre' => $d->nombreTipo(), 'numero' => $d->numeroFormateado(), 'estado' => $d->estado]),
                'cobros' => $c->imputaciones->map(fn($i) => ['cobro_id' => $i->cobro_id, 'numero' => $i->cobro?->numeroFormateado(), 'fecha' => $i->cobro?->fecha->format('d/m/Y'), 'monto' => (float) $i->monto, 'estado' => $i->cobro?->estado]),
                'acopio' => $c->acopio ? ['id' => $c->acopio->id, 'estado' => $c->acopio->estado, 'fecha_limite' => $c->acopio->fecha_limite?->format('d/m/Y'), 'items' => $c->acopio->items->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'facturada' => (float) $i->cantidad_facturada, 'retirada' => (float) $i->cantidad_retirada, 'pendiente' => $i->pendiente()])] : null,
                'sucursal' => $c->location?->name, 'usuario' => $c->user?->name, 'notas' => $c->notas, 'afip_respuesta' => $c->afip_respuesta,
                'neto' => (float) $c->neto, 'iva' => (float) $c->iva, 'percepciones' => (float) $c->percepciones,
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

    public function imprimir(int $id)
    {
        $c = Comprobante::ventas()->with(['items', 'contact', 'business', 'location'])->findOrFail($id);
        return view('comprobantes.imprimir', ['c' => $c, 'b' => $c->business]);
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
            'notas'           => 'nullable|string|max:2000',
            'items'           => 'required|array|min:1',
            'items.*.product_id'   => 'nullable|integer|exists:products,id',
            'items.*.descripcion'  => 'nullable|string|max:255',
            'items.*.cantidad'     => 'required|numeric|gt:0',
            'items.*.unidad'       => 'nullable|string|max:10',
            'items.*.precio_unit'  => 'required|numeric|min:0',
            'items.*.descuento'    => 'nullable|numeric|min:0|max:100',
            'items.*.alicuota_iva' => 'nullable|numeric|in:0,2.5,5,10.5,21,27',
        ]);
    }

    private function datosForm(Request $request, ?Comprobante $c): array
    {
        $user = $request->user();
        $origen = $request->origen_id ? Comprobante::ventas()->with('items')->find($request->origen_id) : null;
        return [
            'comprobante' => $c ? array_merge($this->resumir($c), [
                'contact_id' => $c->contact_id, 'punto_venta_id' => $c->punto_venta_id, 'vendedor_id' => $c->vendedor_id, 'origen_id' => $c->origen_id, 'condicion' => $c->condicion, 'es_acopio' => $c->es_acopio, 'notas' => $c->notas,
                'fecha' => $c->fecha->toDateString(),
                'items' => $c->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva]),
            ]) : null,
            'vendedores' => \App\Models\Vendedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'user_id']),
            'vendedorDefault' => \App\Models\Vendedor::deUsuario($user->id)?->id,
            'tipoInicial' => $c ? preg_replace('/^(F|NC|ND)[ABCE]$/', '$1X', $c->tipo) : $request->input('tipo', 'FX'),
            'origen' => $origen ? ['id' => $origen->id, 'nombre' => $origen->nombreTipo(), 'numero' => $origen->numeroFormateado(), 'contact_id' => $origen->contact_id, 'items' => $origen->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'unidad' => $i->unidad, 'precio_unit' => (float) $i->precio_unit, 'descuento' => (float) $i->descuento, 'alicuota_iva' => (float) $i->alicuota_iva])] : null,
            'tipos' => [
                ['key' => 'FX', 'label' => 'Factura (A/B/C según cliente)'], ['key' => 'PRE', 'label' => 'Presupuesto'], ['key' => 'REM', 'label' => 'Remito'],
                ['key' => 'NCX', 'label' => 'Nota de crédito'], ['key' => 'NDX', 'label' => 'Nota de débito'],
            ],
            'clientes' => Contact::customers()->where('is_active', true)->with('tipoCliente:id,nombre')->orderBy('name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'cuit' => $c->cuit, 'condicion_iva' => $c->condicion_iva, 'lista_precios' => $c->lista_precios, 'dias_pago' => $c->dias_pago, 'descuento' => (float) $c->descuento, 'balance' => (float) $c->balance, 'credit_limit' => (float) $c->credit_limit, 'tipo' => $c->tipoCliente?->nombre]),
            'productos' => Product::where('active', true)->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'iva' => (float) $p->iva, 'stock' => (float) $p->stock, 'precios' => [1 => (float) $p->price, 2 => $p->precioLista(2), 3 => $p->precioLista(3), 4 => $p->precioLista(4), 5 => $p->precioLista(5)]]),
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
            'cliente' => $c->contact?->name, 'contact_id' => $c->contact_id, 'total' => (float) $c->total, 'saldo' => (float) $c->saldo,
            'estado' => $c->estado, 'estado_cobro' => $c->estadoCobro(), 'vencido' => $c->vencido(), 'afip_estado' => $c->afip_estado,
            'cae' => $c->cae, 'cae_vto' => $c->cae_vto?->format('d/m/Y'), 'es_acopio' => $c->es_acopio, 'condicion' => $c->condicion, 'fiscal' => $c->esFiscal(),
        ];
    }

    private function conversionesPosibles(Comprobante $c): array
    {
        if ($c->estado !== 'emitido') {
            return [];
        }
        return match ($c->def()['grupo']) {
            'presupuesto' => [['tipo' => 'FX', 'label' => 'Facturar'], ['tipo' => 'REM', 'label' => 'Hacer remito']],
            'remito'      => $c->derivados->contains(fn($d) => $d->esFactura() && $d->estado !== 'anulado') ? [] : [['tipo' => 'FX', 'label' => 'Facturar']],
            'factura'     => [['tipo' => 'NCX', 'label' => 'Nota de crédito'], ['tipo' => 'NDX', 'label' => 'Nota de débito'], ...(! $c->es_acopio && ! $c->derivados->contains(fn($d) => $d->tipo === 'REM') ? [['tipo' => 'REM', 'label' => 'Hacer remito']] : [])],
            default       => [],
        };
    }
}
