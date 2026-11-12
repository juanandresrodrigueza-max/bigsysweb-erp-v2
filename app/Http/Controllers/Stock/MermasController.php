<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Merma;
use App\Models\Product;
use App\Services\Stock\MermasService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 89: Stock → Mermas y cortesías.
class MermasController extends Controller
{
    public function index(Request $request, MermasService $svc)
    {
        $desde = $request->input('desde', today()->startOfMonth()->toDateString()); $hasta = $request->input('hasta', today()->toDateString());
        $q = Merma::with('product:id,name,sku,unit', 'user:id,name', 'deposito:id,nombre')->whereBetween('fecha', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($request->motivo, fn($q, $m) => $q->where('motivo', $m))
            ->when($request->buscar, fn($q, $b) => $q->whereHas('product', fn($p) => $p->where('name', 'like', "%{$b}%")->orWhere('sku', 'like', "%{$b}%")))
            ->orderByDesc('fecha')->orderByDesc('id');
        return Inertia::render('Stock/Mermas', [
            'lista' => $q->paginate(30)->withQueryString()->through(fn($m) => ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'articulo' => $m->product?->name, 'sku' => $m->product?->sku, 'unidad' => $m->product?->unit,
                'cantidad' => (float) $m->cantidad, 'importe' => $m->importe(), 'motivo' => Merma::MOTIVOS[$m->motivo] ?? $m->motivo, 'motivo_key' => $m->motivo, 'notas' => $m->notas, 'usuario' => $m->user?->name, 'deposito' => $m->deposito?->nombre, 'comanda' => (bool) $m->comanda_item_id]),
            'resumen' => $svc->resumen($desde, $hasta),
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'motivo' => $request->motivo ?? '', 'buscar' => $request->buscar ?? ''],
            'motivos' => Merma::MOTIVOS,
            'depositos' => Deposito::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'articulos' => Product::where('active', true)->orderBy('name')->limit(3000)->get(['id', 'name', 'sku', 'unit', 'stock']),
        ]);
    }

    public function store(Request $request, MermasService $svc)
    {
        $d = $request->validate(['product_id' => 'required|exists:products,id', 'deposito_id' => 'nullable|exists:depositos,id', 'cantidad' => 'required|numeric|min:0.001', 'motivo' => 'required|in:' . implode(',', array_keys(Merma::MOTIVOS)), 'notas' => 'nullable|string|max:200']);
        $p = Product::findOrFail($d['product_id']);
        $m = $svc->registrar($request->user(), $p, (float) $d['cantidad'], $d['motivo'], ! empty($d['deposito_id']) ? Deposito::findOrFail($d['deposito_id']) : null, $d['notas'] ?? null);
        return back()->with('success', "Merma registrada: {$d['cantidad']} {$p->unit} de {$p->name} (" . Merma::MOTIVOS[$m->motivo] . ').');
    }
}
