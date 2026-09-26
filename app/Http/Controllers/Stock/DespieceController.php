<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Despiece;
use App\Models\DespieceOperacion;
use App\Models\Product;
use App\Services\Stock\DespieceService;
use App\Support\Balanza;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// Fase 27.3: carnicería y pesables. Plantillas de despiece, despiece con rinde y costo por corte, y archivo PLU para la balanza.
class DespieceController extends Controller
{
    public function __construct(private DespieceService $svc) {}

    public function index(Request $request)
    {
        return Inertia::render('Stock/Despiece', [
            'plantillas' => Despiece::with('product:id,name,cost,unit', 'cortes.product:id,name,price,unit')->orderBy('nombre')->get()->map(fn($d) => ['id' => $d->id, 'nombre' => $d->nombre, 'product_id' => $d->product_id, 'materia' => $d->product?->name, 'costo_kg' => $d->product?->costoPesos(), 'activo' => $d->activo,
                'cortes' => $d->cortes->map(fn($c) => ['product_id' => $c->product_id, 'nombre' => $c->product?->name, 'precio' => (float) ($c->product?->price ?? 0), 'rinde' => (float) $c->rinde])->values()]),
            'operaciones' => DespieceOperacion::with('items.product', 'despiece.product')->orderByDesc('fecha')->orderByDesc('id')->limit(20)->get()->map(fn($o) => $this->svc->resumen($o)),
            'productos' => Product::where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'unit', 'price', 'cost', 'pesable', 'plu']),
            'pesables' => Product::where('active', true)->where('pesable', true)->orderByRaw('plu IS NULL')->orderBy('plu')->get(['id', 'name', 'plu', 'price', 'unit', 'dias_vencimiento']),
            'depositos' => Deposito::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $b = $request->user()->business_id;
        $d = $request->validate(['nombre' => 'required|string|max:100', 'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $b)], 'activo' => 'boolean',
            'cortes' => 'required|array|min:1', 'cortes.*.product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $b)], 'cortes.*.rinde' => 'nullable|numeric|min:0|max:100']);
        abort_if(round(collect($d['cortes'])->sum(fn($c) => (float) ($c['rinde'] ?? 0)), 3) > 100, 422, 'Los rindes esperados suman más de 100 %.');
        $p = $id ? Despiece::findOrFail($id) : new Despiece(['business_id' => $b]);
        $p->fill(['nombre' => $d['nombre'], 'product_id' => $d['product_id'], 'activo' => $d['activo'] ?? true])->save();
        $p->cortes()->delete();
        foreach (array_values($d['cortes']) as $i => $c) $p->cortes()->create(['product_id' => $c['product_id'], 'rinde' => $c['rinde'] ?? 0, 'orden' => $i]);
        return back()->with('success', "Plantilla {$p->nombre} guardada.");
    }

    public function ejecutar(Request $request, int $id)
    {
        $d = $request->validate(['kg_entrada' => 'required|numeric|gt:0', 'cortes' => 'required|array', 'cortes.*' => 'nullable|numeric|min:0', 'costo_kg' => 'nullable|numeric|min:0', 'deposito_id' => 'nullable|exists:depositos,id', 'fecha' => 'nullable|date', 'actualizar_costos' => 'boolean', 'notas' => 'nullable|string|max:300']);
        $op = $this->svc->ejecutar(Despiece::with('product', 'cortes')->findOrFail($id), (float) $d['kg_entrada'], $d['cortes'], ! empty($d['deposito_id']) ? Deposito::find($d['deposito_id']) : null, $d['fecha'] ?? null, isset($d['costo_kg']) ? (float) $d['costo_kg'] : null, (bool) ($d['actualizar_costos'] ?? true), $d['notas'] ?? null);
        $r = $this->svc->resumen($op);
        return back()->with('success', "Despiece registrado: {$r['kg_salida']} kg en cortes, merma {$r['merma_kg']} kg ({$r['merma_pct']} %).");
    }

    public function plu(Request $request)
    {
        $lista = max(1, min(6, (int) ($request->lista ?: 1)));
        return response(Balanza::exportar($request->user()->business, $lista), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=plu_balanza.csv']);
    }

    // Lectura de etiqueta de balanza o código del artículo desde la factura.
    public function leer(Request $request)
    {
        $c = trim((string) $request->codigo); $b = $request->user()->business;
        if ($r = Balanza::leer($c, $b, (int) ($request->lista ?: 1))) return response()->json(['producto' => \App\Support\Catalogo::producto($r['product'], 'venta'), 'cantidad' => $r['cantidad'], 'importe' => $r['importe'], 'balanza' => true]);
        $p = Product::where('active', true)->where(fn($q) => $q->where('barcode', $c)->orWhere('sku', $c))->first();
        return $p ? response()->json(['producto' => \App\Support\Catalogo::producto($p, 'venta'), 'cantidad' => 1, 'balanza' => false]) : response()->json(['error' => "No se encontró el código {$c}."], 404);
    }
}
