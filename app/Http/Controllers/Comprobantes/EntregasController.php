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
        $oe = OrdenEntrega::with(['items.comprobante.contact', 'items.comprobante.items', 'user:id,name', 'cobros.cobro'])->findOrFail($id);
        $rend = app(\App\Services\Ventas\RendicionRepartoService::class);
        return Inertia::render('Comprobantes/EntregaVer', [
            'orden' => ['id' => $oe->id, 'numero' => $oe->numeroFormateado(), 'fecha' => $oe->fecha->format('d/m/Y'), 'repartidor' => $oe->repartidor, 'vehiculo' => $oe->vehiculo, 'estado' => $oe->estado, 'notas' => $oe->notas, 'usuario' => $oe->user?->name,
                'items' => $oe->items->map(fn($i) => ['id' => $i->id, 'comprobante_id' => $i->comprobante_id, 'comprobante' => "{$i->comprobante?->nombreTipo()} {$i->comprobante?->numeroFormateado()}", 'cliente' => $i->comprobante?->contact?->name, 'telefono' => $i->comprobante?->contact?->mobile ?: $i->comprobante?->contact?->phone, 'direccion' => $i->direccion, 'estado' => $i->estado, 'observacion' => $i->observacion, 'total' => (float) ($i->comprobante?->total ?? 0), 'contado' => $i->comprobante?->condicion === 'contado' && (float) $i->comprobante->saldo > 0,
                    'detalle' => $i->comprobante?->items->map(fn($x) => rtrim(rtrim(number_format((float) $x->cantidad, 3, ',', '.'), '0'), ',') . " {$x->descripcion}")->implode(' · '),
                    'saldo' => (float) ($i->comprobante?->saldo ?? 0), 'saldo_cliente' => (float) ($i->comprobante?->contact?->balance ?? 0),
                    'cobros' => $oe->cobros->where('orden_entrega_item_id', $i->id)->values()->map(fn($c) => ['id' => $c->id, 'medio' => $c->medio, 'monto' => (float) $c->monto, 'referencia' => $c->referencia, 'datos' => $c->datos, 'recibo' => $c->cobro?->numeroFormateado(), 'cobro_id' => $c->cobro_id])])],
            'estados' => OrdenEntrega::ESTADOS,
            'rendicion' => $oe->rendida_en ? ($oe->rendicion + ['fecha' => $oe->rendida_en->format('d/m/Y H:i')]) : null,
            'esperado' => $rend->esperado($oe), 'medios' => \App\Models\OrdenEntregaCobro::MEDIOS,
            'cajas' => \App\Models\CuentaFondos::where('activa', true)->where('tipo', 'caja')->orderByDesc('es_default')->get(['id', 'nombre']),
            'bancos' => \App\Models\CuentaFondos::where('activa', true)->whereIn('tipo', ['banco', 'billetera'])->orderByDesc('es_default')->get(['id', 'nombre']),
            'categorias' => \App\Models\ExpenseCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function marcarItem(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:pendiente,entregado,no_entregado', 'observacion' => 'nullable|string|max:200']);
        $this->service->marcarItem(OrdenEntregaItem::findOrFail($id), $d['estado'], $d['observacion'] ?? null);
        return back()->with('success', $d['estado'] === 'entregado' ? 'Entrega registrada.' : 'Estado actualizado.');
    }

    // Fase 25.5: lo que el chofer cobró en una entrega.
    public function anotarCobro(Request $request, int $id)
    {
        $d = $request->validate(['medio' => 'required|in:' . implode(',', array_keys(\App\Models\OrdenEntregaCobro::MEDIOS)), 'monto' => 'required|numeric|min:0.01', 'referencia' => 'nullable|string|max:80',
            'banco' => 'nullable|required_if:medio,cheque|string|max:60', 'numero' => 'nullable|required_if:medio,cheque|string|max:30', 'fecha_pago' => 'nullable|date']);
        app(\App\Services\Ventas\RendicionRepartoService::class)->anotarCobro(OrdenEntregaItem::findOrFail($id), $d);
        return back()->with('success', 'Cobro anotado. Entra a la caja cuando se rinda el viaje.');
    }

    public function quitarCobro(int $id)
    {
        app(\App\Services\Ventas\RendicionRepartoService::class)->quitarCobro(\App\Models\OrdenEntregaCobro::findOrFail($id));
        return back()->with('success', 'Cobro quitado.');
    }

    public function rendir(Request $request, int $id)
    {
        $d = $request->validate(['cuenta_fondos_id' => 'required|integer|exists:cuentas_fondos,id', 'cuenta_banco_id' => 'nullable|integer|exists:cuentas_fondos,id', 'efectivo_contado' => 'required|numeric|min:0',
            'viaticos' => 'array|max:20', 'viaticos.*.concepto' => 'nullable|string|max:80', 'viaticos.*.monto' => 'nullable|numeric|min:0', 'viaticos.*.expense_category_id' => 'nullable|integer|exists:expense_categories,id', 'notas' => 'nullable|string|max:300']);
        $oe = app(\App\Services\Ventas\RendicionRepartoService::class)->rendir(OrdenEntrega::findOrFail($id), $d);
        $dif = (float) $oe->rendicion['diferencia'];
        return back()->with('success', "Viaje rendido: " . count($oe->rendicion['recibos']) . ' recibos' . (abs($dif) >= 0.01 ? ' · ' . ($dif < 0 ? 'faltante' : 'sobrante') . ' de $ ' . number_format(abs($dif), 2, ',', '.') : ' · sin diferencias') . '.');
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
