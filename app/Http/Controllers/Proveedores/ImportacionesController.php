<?php

namespace App\Http\Controllers\Proveedores;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Deposito;
use App\Models\DespachoImportacion;
use App\Services\Compras\ImportacionesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 97: Comprar → Importaciones: despachos con FOB, gastos de nacionalización prorrateados y costo nacionalizado al stock.
class ImportacionesController extends Controller
{
    public function __construct(private ImportacionesService $svc) {}

    public function index(Request $request)
    {
        $q = DespachoImportacion::with('contact:id,name')->when($request->estado, fn($q, $e) => $q->where('estado', $e))->orderByDesc('fecha')->orderByDesc('id');
        return Inertia::render('Proveedores/Importaciones', [
            'lista' => $q->paginate(25)->withQueryString()->through(fn($d) => ['id' => $d->id, 'numero' => $d->numeroFormateado(), 'despacho' => $d->despacho, 'fecha' => $d->fecha->format('d/m/Y'), 'proveedor' => $d->contact?->name, 'moneda' => $d->moneda, 'estado' => $d->estado, 'estado_label' => DespachoImportacion::ESTADOS[$d->estado] ?? $d->estado, 'fob_total' => (float) $d->fob_total, 'gastos_total' => (float) $d->gastos_total, 'costo_total' => (float) $d->costo_total, 'items' => $d->items()->count()]),
            'filtros' => $request->only('estado'), 'estados' => DespachoImportacion::ESTADOS, 'resumen' => $this->svc->resumen($request->user()->business),
        ]);
    }

    public function form(Request $request, ?int $id = null)
    {
        $d = $id ? DespachoImportacion::findOrFail($id) : null;
        [$productos, $prodParcial] = \App\Support\Catalogo::productos('compra', $d ? $d->items->pluck('product_id')->filter()->all() : []);
        return Inertia::render('Proveedores/ImportacionForm', [
            'despacho' => $d ? $this->svc->arr($d) : null,
            'proveedores' => Contact::suppliers()->orderBy('name')->get(['id', 'name', 'condicion_iva'])->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'exterior' => $c->condicion_iva === 'Exterior']),
            'productos' => $productos, 'catalogoParcial' => $prodParcial,
            'depositos' => Deposito::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tiposGasto' => collect(DespachoImportacion::GASTOS)->map(fn($g, $k) => ['key' => $k] + $g)->values(),
            'cotizacionUsd' => (float) (\App\Models\Cotizacion::valor($request->user()->business_id) ?: 0),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['contact_id' => 'nullable|integer|exists:contacts,id', 'comprobante_id' => 'nullable|integer', 'deposito_id' => 'nullable|integer|exists:depositos,id', 'despacho' => 'nullable|string|max:40', 'fecha' => 'required|date', 'moneda' => 'required|string|size:3', 'cotizacion' => 'required|numeric|gt:0', 'notas' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'nullable|integer|exists:products,id', 'items.*.descripcion' => 'nullable|string|max:200', 'items.*.cantidad' => 'required|numeric|min:0', 'items.*.fob_unit' => 'required|numeric|min:0',
            'gastos' => 'nullable|array', 'gastos.*.tipo' => 'required_with:gastos|string|max:30', 'gastos.*.concepto' => 'nullable|string|max:120', 'gastos.*.monto' => 'required_with:gastos|numeric|min:0', 'nacionalizar' => 'boolean']);
        $desp = $this->svc->guardar($d, $id ? DespachoImportacion::findOrFail($id) : null);
        if ($request->boolean('nacionalizar')) { $desp = $this->svc->nacionalizar($desp); return redirect("/proveedores/importaciones/{$desp->id}")->with('success', "Despacho {$desp->numeroFormateado()} nacionalizado: la mercadería ya está en el stock con su costo."); }
        return redirect("/proveedores/importaciones/{$desp->id}")->with('success', "Despacho {$desp->numeroFormateado()} guardado.");
    }

    public function nacionalizar(int $id)
    {
        $d = $this->svc->nacionalizar(DespachoImportacion::findOrFail($id));
        return back()->with('success', "Despacho {$d->numeroFormateado()} nacionalizado: la mercadería ya está en el stock con su costo.");
    }

    public function anular(int $id)
    {
        $this->svc->anular(DespachoImportacion::findOrFail($id));
        return redirect('/proveedores/importaciones')->with('success', 'Despacho anulado.');
    }
}
