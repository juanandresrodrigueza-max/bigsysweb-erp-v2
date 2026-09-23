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
            'kpis' => ['abiertas' => $todas->count(), 'urgentes' => $todas->where('prioridad', 'alta')->count(), 'atrasadas' => $todas->filter(fn($o) => $o->fecha_prometida && $o->fecha_prometida->isPast() && ! in_array($o->estado, ['listo'], true))->count(), 'listas' => $todas->where('estado', 'listo')->count(), 'por_aprobar' => $todas->where('estado', 'presupuestado')->count(), 'facturable' => (float) $todas->where('estado', 'listo')->sum('presupuesto')],
        ]);
    }

    public function ver(Request $request, int $id, OrdenesService $svc)
    {
        $o = OrdenTrabajo::with('contact:id,name,phone,email', 'tecnico:id,name', 'items.product:id,name,sku', 'tareas.user:id,name', 'comprobante:id,tipo,punto_venta,numero,total,saldo')->findOrFail($id);
        return Inertia::render('Servicios/Ver', [
            'ot' => $svc->resumir($o) + ['contact_id' => $o->contact_id, 'nombre' => $o->nombre, 'serie' => $o->serie, 'diagnostico' => $o->diagnostico, 'tecnico_id' => $o->tecnico_id, 'fecha_ingreso' => $o->fecha_ingreso->toDateString(), 'fecha_prometida' => $o->fecha_prometida?->toDateString(), 'aprobado_en' => $o->aprobado_en?->format('d/m/Y H:i'), 'entregado_en' => $o->entregado_en?->format('d/m/Y H:i'), 'firma' => $o->firma, 'firma_nombre' => $o->firma_nombre, 'notas' => $o->notas, 'url' => $o->urlPublica(), 'comprobante' => $o->comprobante ? ['id' => $o->comprobante->id, 'label' => $o->comprobante->nombreTipo() . ' ' . $o->comprobante->numeroFormateado(), 'saldo' => (float) $o->comprobante->saldo] : null, 'total_items' => $o->totalItems(),
                'items' => $o->items->map(fn($i) => ['id' => $i->id, 'tipo' => $i->tipo, 'descripcion' => $i->descripcion, 'sku' => $i->product?->sku, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'total' => (float) $i->total]),
                'tareas' => $o->tareas->map(fn($t) => ['id' => $t->id, 'descripcion' => $t->descripcion, 'hecha' => $t->hecha, 'usuario' => $t->user?->name, 'hecha_en' => $t->hecha_en?->format('d/m H:i')])],
            'estados' => OrdenTrabajo::ESTADOS, 'prioridades' => OrdenTrabajo::PRIORIDADES,
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']), 'tecnicos' => User::where('business_id', $request->user()->business_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'productos' => Product::where('active', true)->orderBy('name')->limit(500)->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'precio' => $p->precioLista(1), 'tipo' => $p->tipo, 'stock' => (float) $p->stock]),
        ]);
    }

    private function reglas(): array
    {
        return ['contact_id' => 'nullable|integer', 'nombre' => 'nullable|string|max:120', 'telefono' => 'nullable|string|max:40', 'equipo' => 'required|string|max:120', 'marca_modelo' => 'nullable|string|max:120', 'serie' => 'nullable|string|max:80', 'falla' => 'required|string|max:2000', 'diagnostico' => 'nullable|string|max:2000', 'prioridad' => 'nullable|in:baja,normal,alta', 'tecnico_id' => 'nullable|integer', 'fecha_ingreso' => 'nullable|date', 'fecha_prometida' => 'nullable|date', 'presupuesto' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:2000'];
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
        $svc->item(OrdenTrabajo::findOrFail($id), $d);
        return back()->with('success', 'Ítem agregado a la hoja de trabajo.');
    }

    public function borrarItem(int $id, int $item)
    {
        $ot = OrdenTrabajo::findOrFail($id); abort_if($ot->comprobante_id, 422, 'La orden ya está facturada.');
        OrdenTrabajoItem::where('orden_trabajo_id', $id)->findOrFail($item)->delete();
        return back()->with('success', 'Ítem quitado.');
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
        $d = $request->validate(['condicion' => 'nullable|in:contado,cta_cte']);
        $c = $svc->facturar(OrdenTrabajo::findOrFail($id), $d['condicion'] ?? 'contado');
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
        $d = $request->validate(['respuesta' => 'required|in:aprobar,rechazar', 'nombre' => 'nullable|string|max:120']);
        $svc->respuestaCliente($o, $d['respuesta'] === 'aprobar', $d['nombre'] ?? null);
        return redirect("/ot/{$token}");
    }
}
