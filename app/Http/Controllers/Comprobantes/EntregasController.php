<?php

namespace App\Http\Controllers\Comprobantes;

use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\OrdenEntrega;
use App\Models\OrdenEntregaItem;
use App\Services\Ventas\EntregasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Pendientes de entrega/facturación y hojas de reparto.
class EntregasController extends Controller
{
    public function __construct(private EntregasService $service) {}

    public function pendientes()
    {
        $fmt = fn($c, $campo) => ['id' => $c->id, 'nombre' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m/Y'), 'cliente' => $c->contact?->name, 'contact_id' => $c->contact_id, 'total' => (float) $c->total, 'dias' => $c->fecha->diffInDays(today()),
            'items' => $c->items->map(fn($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'unidad' => $i->unidad, 'cantidad' => (float) $i->cantidad, 'hecho' => (float) $i->$campo, 'pendiente' => round(max(0, (float) $i->cantidad - (float) $i->$campo), 3)])->filter(fn($i) => $i['pendiente'] > 0)->values()];
        return Inertia::render('Comprobantes/Pendientes', [
            'remitos' => $this->service->remitosSinFacturar()->map(fn($c) => $fmt($c, 'cantidad_facturada')),
            'facturas' => $this->service->facturasSinEntregar()->map(fn($c) => $fmt($c, 'cantidad_entregada')),
        ]);
    }

    // Facturar o remitir parcialmente: items {item_id: cantidad}
    public function parcial(Request $request, int $id)
    {
        $d = $request->validate(['tipo' => 'required|in:FX,REM', 'items' => 'required|array', 'condicion' => 'nullable|in:contado,cta_cte']);
        $c = $this->service->convertirParcial(Comprobante::ventas()->findOrFail($id), $d['tipo'], $d['items'], ['condicion' => $d['condicion'] ?? null]);
        return redirect("/comprobantes/{$c->id}")->with('success', "{$c->nombreTipo()} {$c->numeroFormateado()} emitido.");
    }

    public function ordenes(Request $request)
    {
        $q = OrdenEntrega::withCount('items')->when($request->estado, fn($q, $e) => $q->where('estado', $e), fn($q) => $q->whereIn('estado', ['pendiente', 'en_curso']))->orderByDesc('fecha')->orderByDesc('id');
        return Inertia::render('Comprobantes/Entregas', [
            'ordenes' => $q->limit(50)->get()->map(fn($o) => ['id' => $o->id, 'numero' => $o->numeroFormateado(), 'fecha' => $o->fecha->format('d/m/Y'), 'repartidor' => $o->repartidor, 'estado' => $o->estado, 'items' => $o->items_count, 'entregados' => $o->items()->where('estado', 'entregado')->count()]),
            'filtros' => $request->only('estado'), 'estados' => OrdenEntrega::ESTADOS,
            // Candidatos: facturas con entrega pendiente y remitos emitidos de los últimos 60 días no incluidos en hojas abiertas
            'candidatos' => Comprobante::ventas()->emitidos()->with('contact:id,name,address,city')->where(fn($q) => $q->where('tipo', 'REM')->orWhere(fn($w) => $w->facturas()->where('entrega_pendiente', true)))->where('fecha', '>=', today()->subDays(60))
                ->whereNotIn('id', OrdenEntregaItem::whereHas('hoja', fn($q) => $q->whereIn('estado', ['pendiente', 'en_curso']))->pluck('comprobante_id'))->orderByDesc('fecha')->get()
                ->filter(fn($c) => $c->tipo === 'REM' ? $c->derivados->isEmpty() || true : $c->pendienteEntrega() > 0)
                ->map(fn($c) => ['id' => $c->id, 'label' => "{$c->nombreTipo()} {$c->numeroFormateado()} · {$c->contact?->name}", 'direccion' => trim(($c->contact?->address ?? '') . ' ' . ($c->contact?->city ?? '')), 'fecha' => $c->fecha->format('d/m')])->values(),
        ]);
    }

    public function crearOrden(Request $request)
    {
        $d = $request->validate(['fecha' => 'required|date', 'repartidor' => 'nullable|string|max:80', 'vehiculo' => 'nullable|string|max:40', 'notas' => 'nullable|string|max:500', 'comprobantes' => 'required|array|min:1', 'direcciones' => 'nullable|array']);
        $oe = $this->service->crearOrden($d);
        return redirect("/comprobantes/entregas/{$oe->id}")->with('success', "Hoja de reparto {$oe->numeroFormateado()} creada.");
    }

    public function verOrden(int $id)
    {
        $oe = OrdenEntrega::with(['items.comprobante.contact', 'items.comprobante.items', 'user:id,name'])->findOrFail($id);
        return Inertia::render('Comprobantes/EntregaVer', [
            'orden' => ['id' => $oe->id, 'numero' => $oe->numeroFormateado(), 'fecha' => $oe->fecha->format('d/m/Y'), 'repartidor' => $oe->repartidor, 'vehiculo' => $oe->vehiculo, 'estado' => $oe->estado, 'notas' => $oe->notas, 'usuario' => $oe->user?->name,
                'items' => $oe->items->map(fn($i) => ['id' => $i->id, 'comprobante_id' => $i->comprobante_id, 'comprobante' => "{$i->comprobante?->nombreTipo()} {$i->comprobante?->numeroFormateado()}", 'cliente' => $i->comprobante?->contact?->name, 'telefono' => $i->comprobante?->contact?->mobile ?: $i->comprobante?->contact?->phone, 'direccion' => $i->direccion, 'estado' => $i->estado, 'observacion' => $i->observacion, 'total' => (float) ($i->comprobante?->total ?? 0), 'contado' => $i->comprobante?->condicion === 'contado' && (float) $i->comprobante->saldo > 0,
                    'detalle' => $i->comprobante?->items->map(fn($x) => rtrim(rtrim(number_format((float) $x->cantidad, 3, ',', '.'), '0'), ',') . " {$x->descripcion}")->implode(' · ')])],
            'estados' => OrdenEntrega::ESTADOS,
        ]);
    }

    public function marcarItem(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:pendiente,entregado,no_entregado', 'observacion' => 'nullable|string|max:200']);
        $this->service->marcarItem(OrdenEntregaItem::findOrFail($id), $d['estado'], $d['observacion'] ?? null);
        return back()->with('success', $d['estado'] === 'entregado' ? 'Entrega registrada.' : 'Estado actualizado.');
    }

    public function estadoOrden(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:pendiente,en_curso,entregada,cancelada']);
        $this->service->estadoOrden(OrdenEntrega::findOrFail($id), $d['estado']);
        return back()->with('success', 'Hoja actualizada.');
    }

    public function imprimirOrden(int $id)
    {
        $oe = OrdenEntrega::with(['items.comprobante.contact', 'items.comprobante.items', 'business'])->findOrFail($id);
        return view('entregas.hoja', ['oe' => $oe, 'b' => $oe->business]);
    }
}
