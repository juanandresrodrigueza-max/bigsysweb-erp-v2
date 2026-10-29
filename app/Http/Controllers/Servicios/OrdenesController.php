<?php

namespace App\Http\Controllers\Servicios;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\OrdenTrabajo;
use App\Models\OrdenTrabajoItem;
use App\Models\OrdenTrabajoTarea;
use App\Models\Product;
use App\Models\User;
use App\Services\Servicios\OrdenesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Servicio técnico: tablero de órdenes de trabajo y ficha de cada una.
class OrdenesController extends Controller
{
    public function index(Request $request, OrdenesService $svc)
    {
        $b = $request->user()->business_id;
        $todas = OrdenTrabajo::whereNotIn('estado', ['entregado', 'cancelado'])->get();
        return Inertia::render('Servicios/Index', [
            'tablero' => $svc->tablero(), 'estados' => OrdenTrabajo::ESTADOS, 'prioridades' => OrdenTrabajo::PRIORIDADES,
            'entregadas' => OrdenTrabajo::with('contact:id,name')->whereIn('estado', ['entregado', 'cancelado'])->orderByDesc('updated_at')->limit(15)->get()->map(fn($o) => $svc->resumir($o)),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']), 'tecnicos' => User::where('business_id', $b)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'config' => app(\App\Services\Servicios\TallerService::class)->config(), 'rubros' => collect(\App\Services\Servicios\TallerService::RUBROS)->map(fn($r) => $r['nombre']),
            'para_revisar' => app(\App\Services\Servicios\TallerHistoriaService::class)->paraRevisar(),
            'kpis' => ['esperando' => $todas->where('estado', 'esperando')->count(), 'abiertas' => $todas->count(), 'urgentes' => $todas->where('prioridad', 'alta')->count(), 'atrasadas' => $todas->filter(fn($o) => $o->fecha_prometida && $o->fecha_prometida->isPast() && ! in_array($o->estado, ['listo'], true))->count(), 'listas' => $todas->where('estado', 'listo')->count(), 'por_aprobar' => $todas->where('estado', 'presupuestado')->count(), 'facturable' => (float) $todas->where('estado', 'listo')->sum('presupuesto')],
        ]);
    }

    public function ver(Request $request, int $id, OrdenesService $svc)
    {
        $o = OrdenTrabajo::with('contact:id,name,phone,email', 'tecnico:id,name', 'items.product:id,name,sku', 'tareas.user:id,name', 'comprobante:id,tipo,punto_venta,numero,total,saldo', 'fotos')->findOrFail($id);
        $taller = app(\App\Services\Servicios\TallerService::class);
        return Inertia::render('Servicios/Ver', [
            'config' => $taller->config(), 'que_sigue' => $taller->queSigue($o),
            'checklist' => app(\App\Services\Servicios\TallerHistoriaService::class)->checklist($taller->config()['rubro']), 'vida' => app(\App\Services\Servicios\TallerHistoriaService::class)->vidaEquipo($o), 'flota' => app(\App\Services\Servicios\TallerHistoriaService::class)->flota($o->contact_id), 'uso_diario' => app(\App\Services\Servicios\TallerHistoriaService::class)->usoDiario($o->serie), 'ia' => \App\Support\Ia::tiene($request->user()->business()->first()), 'pendientes' => $taller->pendientesAnteriores($o), 'plantillas' => \App\Models\ServicioPlantilla::orderBy('nombre')->get(['id', 'nombre']),
            'fotos' => $o->fotos->map(fn($f) => ['id' => $f->id, 'momento' => $f->momento, 'nota' => $f->nota, 'url' => "/servicios/{$o->id}/fotos/{$f->id}"])->groupBy('momento'), 'momentos' => \App\Models\OrdenTrabajoFoto::MOMENTOS,
            'ot' => $svc->resumir($o) + ['contact_id' => $o->contact_id, 'nombre' => $o->nombre, 'serie' => $o->serie, 'diagnostico' => $o->diagnostico, 'tecnico_id' => $o->tecnico_id, 'fecha_ingreso' => $o->fecha_ingreso->toDateString(), 'fecha_prometida' => $o->fecha_prometida?->toDateString(), 'aprobado_en' => $o->aprobado_en?->format('d/m/Y H:i'), 'entregado_en' => $o->entregado_en?->format('d/m/Y H:i'), 'firma' => $o->firma, 'firma_nombre' => $o->firma_nombre, 'notas' => $o->notas, 'url' => $o->urlPublica(), 'datos_equipo' => $o->datos_equipo ?? [], 'recepcion' => $o->recepcion, 'volver_el' => $o->volver_el?->toDateString(), 'volver_medidor' => $o->volver_medidor !== null ? (float) $o->volver_medidor : null, 'medidor' => $o->medidor !== null ? (float) $o->medidor : null, 'aprobacion' => $o->aprobacion ? collect($o->aprobacion)->except('firma')->all() : null, 'comprobante' => $o->comprobante ? ['id' => $o->comprobante->id, 'label' => $o->comprobante->nombreTipo() . ' ' . $o->comprobante->numeroFormateado(), 'saldo' => (float) $o->comprobante->saldo] : null, 'total_items' => $o->totalItems(),
                'items' => $o->items->map(fn($i) => ['id' => $i->id, 'tipo' => $i->tipo, 'descripcion' => $i->descripcion, 'sku' => $i->product?->sku, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'total' => (float) $i->total, 'aprobado' => $i->aprobado, 'pendiente_proxima' => $i->pendiente_proxima, 'tecnicos' => $i->tecnicos ?? [], 'de_antes' => (bool) $i->viene_de_item_id]),
                'tareas' => $o->tareas->map(fn($t) => ['id' => $t->id, 'descripcion' => $t->descripcion, 'hecha' => $t->hecha, 'usuario' => $t->user?->name, 'hecha_en' => $t->hecha_en?->format('d/m H:i')])],
            'estados' => OrdenTrabajo::ESTADOS, 'prioridades' => OrdenTrabajo::PRIORIDADES,
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']), 'tecnicos' => User::where('business_id', $request->user()->business_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'productos' => Product::where('active', true)->orderBy('name')->limit(500)->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'precio' => $p->precioLista(1), 'tipo' => $p->tipo, 'stock' => (float) $p->stock]),
        ]);
    }

    private function reglas(): array
    {
        return ['contact_id' => 'nullable|integer', 'nombre' => 'nullable|string|max:120', 'telefono' => 'nullable|string|max:40', 'equipo' => 'required|string|max:120', 'marca_modelo' => 'nullable|string|max:120', 'serie' => 'nullable|string|max:80', 'falla' => 'required|string|max:2000', 'diagnostico' => 'nullable|string|max:2000', 'prioridad' => 'nullable|in:baja,normal,alta', 'tecnico_id' => 'nullable|integer', 'fecha_ingreso' => 'nullable|date', 'fecha_prometida' => 'nullable|date', 'presupuesto' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:2000', 'datos_equipo' => 'nullable|array', 'datos_equipo.*' => 'nullable|string|max:120', 'medidor' => 'nullable|numeric|min:0'];
    }

    public function crear(Request $request, OrdenesService $svc)
    {
        $ot = $svc->crear($request->validate($this->reglas()));
        return redirect("/servicios/{$ot->id}")->with('success', "Orden {$ot->numeroFormateado()} creada.");
    }

    public function actualizar(Request $request, int $id, OrdenesService $svc)
    {
        $svc->actualizar(OrdenTrabajo::findOrFail($id), $request->validate($this->reglas()));
        return back()->with('success', 'Orden actualizada.');
    }

    public function estado(Request $request, int $id, OrdenesService $svc)
    {
        $d = $request->validate(['estado' => 'required|string']);
        $r = $svc->estado(OrdenTrabajo::findOrFail($id), $d['estado']);
        $msg = 'Orden ' . strtolower(OrdenTrabajo::ESTADOS[$d['estado']]) . '.';
        if ($r['aviso']) return $r['aviso']['enviado'] ? back()->with('success', $msg . ' Aviso enviado por WhatsApp.') : back()->with('success', $msg . ' Abrí WhatsApp para avisarle al cliente.')->with('abrir', $r['aviso']['link']);
        return back()->with('success', $msg);
    }

    public function item(Request $request, int $id, OrdenesService $svc)
    {
        $d = $request->validate(['product_id' => 'nullable|integer', 'tipo' => 'nullable|in:material,mano_obra', 'descripcion' => 'nullable|string|max:200', 'cantidad' => 'required|numeric|gt:0', 'precio_unit' => 'nullable|numeric|min:0', 'actualizar_presupuesto' => 'boolean']);
        $it = $svc->item(OrdenTrabajo::findOrFail($id), $d);
        return $it->aviso ? back()->with('error', $it->aviso) : back()->with('success', 'Ítem agregado a la hoja de trabajo.');
    }

    public function borrarItem(int $id, int $item)
    {
        $ot = OrdenTrabajo::findOrFail($id); abort_if($ot->comprobante_id, 422, 'La orden ya está facturada.');
        OrdenTrabajoItem::where('orden_trabajo_id', $id)->findOrFail($item)->delete();
        $aviso = app(\App\Services\Servicios\TallerService::class)->revisarAprobacion($ot);
        return $aviso ? back()->with('error', $aviso) : back()->with('success', 'Ítem quitado.');
    }

    public function tarea(Request $request, int $id, OrdenesService $svc)
    {
        $d = $request->validate(['descripcion' => 'required|string|max:200']);
        $svc->tarea(OrdenTrabajo::findOrFail($id), $d['descripcion']);
        return back();
    }

    public function tareaHecha(int $id, int $tarea)
    {
        $t = OrdenTrabajoTarea::where('orden_trabajo_id', $id)->findOrFail($tarea);
        $t->update(['hecha' => ! $t->hecha, 'hecha_en' => $t->hecha ? null : now()]);
        return back();
    }

    public function firmar(Request $request, int $id, OrdenesService $svc)
    {
        $d = $request->validate(['firma' => 'required|string|max:200000', 'nombre' => 'nullable|string|max:120']);
        $svc->firmar(OrdenTrabajo::findOrFail($id), $d['firma'], $d['nombre'] ?? null);
        return back()->with('success', 'Firma guardada.');
    }

    public function facturar(Request $request, int $id, OrdenesService $svc)
    {
        $d = $request->validate(['condicion' => 'nullable|in:contado,cta_cte', 'tipo' => 'nullable|in:FX,REM']);
        $c = $svc->facturar(OrdenTrabajo::findOrFail($id), $d['condicion'] ?? 'contado', $d['tipo'] ?? 'FX');
        return redirect("/comprobantes/{$c->id}")->with('success', "{$c->nombreTipo()} {$c->numeroFormateado()} emitida. Registrá el cobro y entregá el equipo.");
    }

    // ---- Público: el cliente ve el estado, aprueba el presupuesto ----
    public function publico(string $token)
    {
        $o = OrdenTrabajo::withoutGlobalScopes()->with('business', 'contact', 'items', 'tecnico')->where('token', $token)->firstOrFail();
        return view('servicios.publico', ['ot' => $o, 'b' => $o->business]);
    }

    public function responder(Request $request, string $token, OrdenesService $svc)
    {
        $o = OrdenTrabajo::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $d = $request->validate(['respuesta' => 'required|in:aprobar,rechazar', 'nombre' => 'nullable|string|max:120', 'aprobados' => 'nullable|array', 'aprobados.*' => 'integer', 'firma' => 'nullable|string|max:200000']);
        $svc->respuestaCliente($o, $d['respuesta'] === 'aprobar', $d['nombre'] ?? null, $d['respuesta'] === 'aprobar' ? ($d['aprobados'] ?? ($request->boolean('por_item') ? [] : null)) : null, $d['firma'] ?? null);
        return redirect("/ot/{$token}");
    }

    // ---- Fase 36 ----
    public function config(Request $request)
    {
        $d = $request->validate(['rubro' => 'required|in:' . implode(',', array_keys(\App\Services\Servicios\TallerService::RUBROS)), 'equipo' => 'nullable|string|max:40', 'serie' => 'nullable|string|max:40', 'medidor' => 'nullable|string|max:20', 'tecnico' => 'nullable|string|max:40', 'campos' => 'nullable|array', 'campos.*' => 'nullable|string|max:40', 'pago_tecnico_pct' => 'nullable|numeric|min:0|max:100']);
        app(\App\Services\Servicios\TallerService::class)->guardarConfig($d);
        return back()->with('success', 'Listo: las órdenes ya hablan como tu taller.');
    }

    public function esperar(Request $request, int $id)
    {
        $d = $request->validate(['motivo' => 'required|string|max:160']);
        app(\App\Services\Servicios\TallerService::class)->esperar(OrdenTrabajo::findOrFail($id), $d['motivo']);
        return back()->with('success', 'Queda esperando: ' . $d['motivo'] . '.');
    }

    public function reparto(Request $request, int $id, int $item)
    {
        $d = $request->validate(['reparto' => 'present|array', 'reparto.*.user_id' => 'nullable|integer', 'reparto.*.pct' => 'nullable|numeric|min:0|max:100']);
        app(\App\Services\Servicios\TallerService::class)->repartir(OrdenTrabajoItem::where('orden_trabajo_id', $id)->findOrFail($item), $d['reparto']);
        return back()->with('success', 'Mano de obra repartida.');
    }

    public function traerPendiente(int $id, int $item)
    {
        $ot = OrdenTrabajo::findOrFail($id); $t = app(\App\Services\Servicios\TallerService::class);
        $t->traerPendiente($ot, $item); $t->revisarAprobacion($ot);
        return back()->with('success', 'Agregado a esta orden lo que había quedado pendiente.');
    }

    public function guardarPlantilla(Request $request, int $id)
    {
        $d = $request->validate(['nombre' => 'required|string|max:120']);
        app(\App\Services\Servicios\TallerService::class)->guardarPlantilla(OrdenTrabajo::findOrFail($id), $d['nombre']);
        return back()->with('success', "Plantilla «{$d['nombre']}» guardada.");
    }

    public function aplicarPlantilla(int $id, int $plantilla, OrdenesService $svc)
    {
        $ot = OrdenTrabajo::findOrFail($id); abort_if($ot->comprobante_id, 422, 'La orden ya está facturada.');
        $n = app(\App\Services\Servicios\TallerService::class)->aplicarPlantilla($ot, \App\Models\ServicioPlantilla::findOrFail($plantilla), $svc);
        return back()->with('success', "Agregados {$n} ítems de la plantilla.");
    }

    public function subirFoto(Request $request, int $id)
    {
        $d = $request->validate(['momento' => 'required|in:' . implode(',', array_keys(\App\Models\OrdenTrabajoFoto::MOMENTOS)), 'fotos' => 'required|array|max:12', 'fotos.*' => 'file|max:12288|mimes:jpg,jpeg,png,webp,heic,mp4,mov', 'nota' => 'nullable|string|max:200']);
        $ot = OrdenTrabajo::findOrFail($id);
        foreach ($request->file('fotos') as $f) \App\Models\OrdenTrabajoFoto::create(['business_id' => $ot->business_id, 'orden_trabajo_id' => $ot->id, 'user_id' => $request->user()->id, 'momento' => $d['momento'], 'path' => $f->store("servicios/{$ot->business_id}/{$ot->id}"), 'nota' => $d['nota'] ?? null]);
        return back()->with('success', count($request->file('fotos')) . ' foto(s) guardadas.');
    }

    public function verFoto(int $id, int $foto)
    {
        $f = \App\Models\OrdenTrabajoFoto::where('orden_trabajo_id', $id)->findOrFail($foto);
        abort_unless(\Illuminate\Support\Facades\Storage::exists($f->path), 404);
        return \Illuminate\Support\Facades\Storage::response($f->path);
    }

    public function misOrdenes(Request $request)
    {
        return Inertia::render('Servicios/MisOrdenes', ['ordenes' => app(\App\Services\Servicios\TallerService::class)->misOrdenes($request->user()), 'estados' => OrdenTrabajo::ESTADOS, 'config' => app(\App\Services\Servicios\TallerService::class)->config()]);
    }

    public function tecnicos(Request $request)
    {
        $desde = $request->desde ?: today()->startOfMonth()->toDateString(); $hasta = $request->hasta ?: today()->toDateString();
        return Inertia::render('Servicios/Tecnicos', ['periodo' => compact('desde', 'hasta'), 'pago' => app(\App\Services\Servicios\TallerService::class)->pagoTecnicos($desde, $hasta), 'config' => app(\App\Services\Servicios\TallerService::class)->config()]);
    }

    // ---- Fase 36.2, 36.5 y 36.6 ----
    private function historia() { return app(\App\Services\Servicios\TallerHistoriaService::class); }

    public function recepcion(Request $request, int $id)
    {
        $d = $request->validate(['medidor' => 'nullable|numeric|min:0', 'combustible' => 'nullable|string|max:10', 'luces' => 'nullable|array', 'luces.*' => 'string|max:60', 'danios' => 'nullable|array', 'danios.*.zona' => 'nullable|string|max:60', 'danios.*.detalle' => 'nullable|string|max:200', 'danios.*.foto_id' => 'nullable|integer', 'checklist' => 'nullable|array', 'checklist.*' => 'nullable|in:verde,amarillo,rojo', 'sugeridos' => 'nullable|array', 'sugeridos.*' => 'string|max:120', 'observaciones' => 'nullable|string|max:1000']);
        $this->historia()->guardarRecepcion(OrdenTrabajo::findOrFail($id), $d);
        return back()->with('success', 'Recepción guardada.');
    }

    public function recepcionIA(int $id)
    {
        $j = $this->historia()->recepcionConIA(OrdenTrabajo::findOrFail($id));
        return back()->with('success', 'La IA completó la recepción: revisala y corregí lo que haga falta' . (count($j['danios'] ?? []) ? ' (' . count($j['danios']) . ' daños marcados)' : '') . '.');
    }

    // El acta de recepción firmada, en PDF: lo que se vio al recibir el equipo.
    public function acta(int $id)
    {
        $o = OrdenTrabajo::with('business', 'contact', 'fotos')->findOrFail($id);
        $fotos = $o->fotos->where('momento', 'recepcion')->take(6)->map(fn($f) => \Illuminate\Support\Facades\Storage::exists($f->path) && preg_match('/\.(jpe?g|png)$/i', $f->path) ? 'data:' . \Illuminate\Support\Facades\Storage::mimeType($f->path) . ';base64,' . base64_encode(\Illuminate\Support\Facades\Storage::get($f->path)) : null)->filter()->values();
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('servicios.acta', ['o' => $o, 'b' => $o->business, 'cfg' => app(\App\Services\Servicios\TallerService::class)->config(), 'fotos' => $fotos])->setPaper('a4')->stream("acta-{$o->numeroFormateado()}.pdf");
    }

    public function vuelta(Request $request, int $id)
    {
        $d = $request->validate(['meses' => 'nullable|integer|min:1|max:60', 'medidor' => 'nullable|numeric|min:0']);
        $this->historia()->programarVuelta(OrdenTrabajo::findOrFail($id), $d['meses'] ?? null, isset($d['medidor']) ? (float) $d['medidor'] : null);
        return back()->with('success', 'Queda agendado cuándo tiene que volver.');
    }

    public function queVuelvan(Request $request)
    {
        $cfg = $request->user()->business()->first()->servicios_config ?? [];
        return Inertia::render('Servicios/QueVuelvan', ['lista' => $this->historia()->queVuelvan((int) ($request->dias ?: 30)), 'dias' => (int) ($request->dias ?: 30), 'piezas' => $cfg['piezas'] ?? [], 'config' => app(\App\Services\Servicios\TallerService::class)->config()]);
    }

    public function piezas(Request $request)
    {
        $d = $request->validate(['piezas' => 'present|array|max:30', 'piezas.*.nombre' => 'required|string|max:60', 'piezas.*.cada' => 'required|numeric|min:1']);
        $b = $request->user()->business()->first(); $c = $b->servicios_config ?? []; $c['piezas'] = array_values($d['piezas']); $b->servicios_config = $c; $b->save();
        return back()->with('success', 'Alertas por pieza guardadas.');
    }

    public function recordado(int $id)
    {
        $this->historia()->marcarRecordado(OrdenTrabajo::findOrFail($id));
        return back();
    }

    public function cambiarDueno(Request $request, int $id)
    {
        $d = $request->validate(['contact_id' => 'required|integer']);
        $n = $this->historia()->cambiarDueno(OrdenTrabajo::findOrFail($id), Contact::findOrFail($d['contact_id']));
        return back()->with('success', "Cambio de dueño registrado ({$n} orden/es abiertas pasan al nuevo dueño). La historia del equipo queda.");
    }
}
