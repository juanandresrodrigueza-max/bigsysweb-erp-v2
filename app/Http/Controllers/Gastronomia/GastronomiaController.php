<?php

namespace App\Http\Controllers\Gastronomia;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cobro;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\Mesa;
use App\Models\Product;
use App\Models\Rubro;
use App\Services\Gastronomia\ComandaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Salón: plano de mesas, comandas, cocina y cierre con factura.
class GastronomiaController extends Controller
{
    // Precio de carta: con IVA incluido si la empresa es responsable inscripto; el monotributista no discrimina.
    private function precioFinal(Product $p): float
    {
        $ri = (auth()->user()->business->condicion_iva ?? 'Responsable Inscripto') === 'Responsable Inscripto';
        return round((float) $p->price * ($ri ? 1 + (float) $p->iva / 100 : 1), 2);
    }

    private function comandaArr(Comanda $c): array
    {
        $c->loadMissing('items.product:id,name', 'mesa:id,nombre,sector', 'mozo:id,name');
        return [
            'id' => $c->id, 'numero' => $c->numeroFormateado(), 'titulo' => $c->titulo(), 'tipo' => $c->tipo, 'estado' => $c->estado, 'mesa_id' => $c->mesa_id, 'mesa' => $c->mesa?->nombre, 'sector' => $c->mesa?->sector,
            'mozo' => $c->mozo?->name, 'cubiertos' => $c->cubiertos, 'cliente' => $c->cliente, 'direccion' => $c->direccion, 'telefono' => $c->telefono, 'total' => (float) $c->total, 'descuento' => (float) $c->descuento, 'propina' => (float) $c->propina, 'notas' => $c->notas,
            'abierta' => $c->abierta_en?->format('H:i'), 'minutos' => $c->abierta_en ? (int) $c->abierta_en->diffInMinutes(now()) : 0, 'comprobante_id' => $c->comprobante_id,
            'items' => $c->items->map(fn($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'notas' => $i->notas, 'estado' => $i->estado, 'va_cocina' => $i->va_cocina, 'ronda' => $i->ronda, 'enviado' => $i->enviado_en?->format('H:i'), 'listo' => $i->listo_en?->format('H:i')]),
            'pendientes_envio' => $c->items->where('estado', 'pedido')->count(), 'listos' => $c->items->where('estado', 'listo')->count(),
        ];
    }

    public function mesas(Request $request)
    {
        $user = $request->user();
        $mesas = Mesa::with('comandaAbierta.items', 'comandaAbierta.mozo:id,name')->where('business_location_id', $user->current_location_id)->orderBy('sector')->orderBy('orden')->orderBy('nombre')->get();
        $hoy = Comanda::where('estado', 'cerrada')->whereDate('cerrada_en', today());
        return Inertia::render('Gastronomia/Mesas', [
            'mesas' => $mesas->map(fn($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'sector' => $m->sector ?: 'Salón', 'capacidad' => $m->capacidad, 'activa' => $m->activa, 'orden' => $m->orden,
                'comanda' => $m->comandaAbierta ? ['id' => $m->comandaAbierta->id, 'estado' => $m->comandaAbierta->estado, 'total' => (float) $m->comandaAbierta->total, 'minutos' => (int) $m->comandaAbierta->abierta_en->diffInMinutes(now()), 'mozo' => $m->comandaAbierta->mozo?->name, 'cubiertos' => $m->comandaAbierta->cubiertos, 'listos' => $m->comandaAbierta->items->where('estado', 'listo')->count(), 'sin_enviar' => $m->comandaAbierta->items->where('estado', 'pedido')->count()] : null]),
            'otras' => Comanda::abiertas()->whereNull('mesa_id')->with('items', 'mozo:id,name')->orderBy('abierta_en')->get()->map(fn($c) => $this->comandaArr($c)),
            'kpis' => ['abiertas' => Comanda::abiertas()->count(), 'ocupadas' => $mesas->filter(fn($m) => $m->comandaAbierta)->count(), 'mesas' => $mesas->where('activa', true)->count(), 'ventas_hoy' => (float) (clone $hoy)->sum('total'), 'cubiertos_hoy' => (int) (clone $hoy)->sum('cubiertos'), 'comandas_hoy' => (clone $hoy)->count(), 'propinas_hoy' => (float) (clone $hoy)->sum('propina'), 'en_cocina' => ComandaItem::where('estado', 'cocina')->whereHas('comanda', fn($q) => $q->abiertas())->count()],
            'listaSucursales' => $user->business->locations()->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function guardarMesa(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:30', 'sector' => 'nullable|string|max:40', 'capacidad' => 'nullable|integer|min:1|max:50', 'orden' => 'nullable|integer', 'activa' => 'boolean']);
        $m = $id ? Mesa::findOrFail($id) : new Mesa(['business_id' => $request->user()->business_id, 'business_location_id' => $request->user()->current_location_id]);
        $m->fill($d)->save();
        return back()->with('success', 'Mesa guardada.');
    }

    public function eliminarMesa(int $id)
    {
        $m = Mesa::findOrFail($id);
        abort_if($m->comandaAbierta, 422, 'La mesa tiene una comanda abierta.');
        $m->delete();
        return back()->with('success', 'Mesa eliminada.');
    }

    public function abrir(Request $request, ComandaService $svc)
    {
        $d = $request->validate(['mesa_id' => 'nullable|exists:mesas,id', 'tipo' => 'nullable|in:mesa,mostrador,delivery', 'cubiertos' => 'nullable|integer|min:0|max:100', 'cliente' => 'nullable|string|max:120', 'direccion' => 'nullable|string|max:200', 'telefono' => 'nullable|string|max:40']);
        $c = $svc->abrir($d);
        return redirect("/gastronomia/comandas/{$c->id}");
    }

    public function comanda(int $id)
    {
        $c = Comanda::findOrFail($id);
        return Inertia::render('Gastronomia/Comanda', [
            'comanda' => $this->comandaArr($c),
            'productos' => Product::where('active', true)->where('tipo', '!=', 'insumo')->with('rubro:id,nombre,color')->orderByDesc('favorito_pos')->orderBy('name')->get()->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $this->precioFinal($p), 'rubro_id' => $p->rubro_id, 'rubro' => $p->rubro?->nombre, 'color' => $p->rubro?->color, 'va_cocina' => $p->va_cocina, 'favorito' => $p->favorito_pos]),
            'rubros' => Rubro::orderBy('orden')->orderBy('nombre')->get(['id', 'nombre', 'color']),
            'medios' => Cobro::MEDIOS, 'cuentas' => CuentaFondos::where('activa', true)->orderBy('tipo')->get(['id', 'tipo', 'nombre']),
            'clientes' => Contact::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'cuit', 'condicion_iva']),
            'mesas' => Mesa::where('activa', true)->orderBy('sector')->orderBy('nombre')->get(['id', 'nombre', 'sector']),
        ]);
    }

    public function agregar(Request $request, int $id, ComandaService $svc)
    {
        $d = $request->validate(['product_id' => 'nullable|exists:products,id', 'descripcion' => 'nullable|string|max:150', 'cantidad' => 'nullable|numeric|min:0.001', 'precio_unit' => 'nullable|numeric|min:0', 'notas' => 'nullable|string|max:150']);
        $svc->agregar(Comanda::findOrFail($id), $d);
        return back();
    }

    public function quitar(int $id, int $item, ComandaService $svc)
    {
        $svc->quitar(ComandaItem::where('comanda_id', $id)->findOrFail($item));
        return back();
    }

    public function enviar(int $id, ComandaService $svc)
    {
        $n = $svc->enviarCocina(Comanda::findOrFail($id));
        return back()->with('success', $n ? "{$n} ítem" . ($n > 1 ? 's' : '') . " enviado" . ($n > 1 ? 's' : '') . " a cocina." : 'No había nada pendiente de enviar.');
    }

    public function itemEstado(Request $request, int $item, ComandaService $svc)
    {
        $d = $request->validate(['estado' => 'required|in:pedido,cocina,listo,entregado,anulado']);
        $svc->estadoItem(ComandaItem::findOrFail($item), $d['estado']);
        return back();
    }

    public function cuenta(int $id, ComandaService $svc)
    {
        $svc->pedirCuenta(Comanda::findOrFail($id));
        return back()->with('success', 'Cuenta pedida. Cerrala cuando cobres.');
    }

    public function mover(Request $request, int $id)
    {
        $d = $request->validate(['mesa_id' => 'required|exists:mesas,id']);
        $mesa = Mesa::findOrFail($d['mesa_id']);
        abort_if($mesa->comandaAbierta, 422, 'La mesa destino está ocupada.');
        Comanda::findOrFail($id)->update(['mesa_id' => $mesa->id, 'tipo' => 'mesa']);
        return back()->with('success', "Movida a la mesa {$mesa->nombre}.");
    }

    public function cerrar(Request $request, int $id, ComandaService $svc)
    {
        $d = $request->validate(['contact_id' => 'nullable|exists:contacts,id', 'descuento' => 'nullable|numeric|min:0', 'propina' => 'nullable|numeric|min:0', 'a_cuenta' => 'boolean', 'medios' => 'nullable|array', 'medios.*.medio' => 'required|in:' . implode(',', array_keys(Cobro::MEDIOS)), 'medios.*.monto' => 'required|numeric|min:0', 'medios.*.cuenta_fondos_id' => 'nullable|integer', 'medios.*.referencia' => 'nullable|string|max:120']);
        $r = $svc->cerrar(Comanda::findOrFail($id), $d);
        $c = $r['comprobante'];
        return redirect('/gastronomia')->with('success', "{$r['comanda']->titulo()} cerrada: {$c->nombreTipo()} {$c->numeroFormateado()} por $ " . number_format((float) $c->total, 2, ',', '.') . ($r['vuelto'] > 0 ? ' · vuelto $ ' . number_format($r['vuelto'], 2, ',', '.') : ''))->with('pos', ['comprobante_id' => $c->id, 'vuelto' => $r['vuelto']]);
    }

    public function anular(Request $request, int $id, ComandaService $svc)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $svc->anular(Comanda::findOrFail($id), $d['motivo']);
        return redirect('/gastronomia')->with('success', 'Comanda anulada.');
    }

    // Pantalla de cocina: lo pendiente agrupado por comanda, más viejo primero. Se refresca sola.
    public function cocina(Request $request)
    {
        $items = ComandaItem::with('comanda.mesa:id,nombre,sector', 'comanda.mozo:id,name')->whereIn('estado', ['cocina', 'listo'])->where('va_cocina', true)->whereHas('comanda', fn($q) => $q->abiertas())->orderBy('enviado_en')->get();
        $grupos = $items->groupBy('comanda_id')->map(fn($its) => ['comanda_id' => $its->first()->comanda_id, 'titulo' => $its->first()->comanda->titulo(), 'mozo' => $its->first()->comanda->mozo?->name, 'enviado' => $its->min('enviado_en')?->format('H:i'), 'minutos' => (int) ($its->min('enviado_en')?->diffInMinutes(now()) ?? 0),
            'items' => $its->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'notas' => $i->notas, 'estado' => $i->estado, 'ronda' => $i->ronda, 'minutos' => (int) ($i->enviado_en?->diffInMinutes(now()) ?? 0)])->values()])->values();
        if ($request->wantsJson() || $request->boolean('json')) return response()->json($grupos);
        return Inertia::render('Gastronomia/Cocina', ['grupos' => $grupos, 'terminados' => ComandaItem::where('estado', 'entregado')->where('va_cocina', true)->whereDate('updated_at', today())->count()]);
    }
}
