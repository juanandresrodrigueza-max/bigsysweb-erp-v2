<?php

namespace App\Http\Controllers\Produccion;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Services\Produccion\ProduccionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProduccionController extends Controller
{
    public function index(Request $request, ProduccionService $service)
    {
        $q = ProductionOrder::with('recipe:id,name,yield_quantity', 'product:id,name,unit', 'deposito:id,nombre', 'user:id,name')
            ->when($request->estado, fn($q, $e) => $q->where('status', $e), fn($q) => $q->when(! $request->has('estado'), fn($q) => $q->whereIn('status', ['pending', 'in_progress'])))
            ->when($request->estado === '', fn($q) => $q)
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")->orderByDesc('scheduled_at')->orderByDesc('id');

        $ordenes = $q->paginate(30)->withQueryString()->through(fn($o) => $this->orden($o, $service));
        $mes = now()->startOfMonth();
        $abierta = $request->orden ? ProductionOrder::find($request->orden) : null;

        return Inertia::render('Produccion/Index', [
            'ordenes' => $ordenes, 'filtros' => $request->only('estado'), 'estados' => ProductionOrder::ESTADOS,
            'formulas' => Recipe::with('product:id,name,unit')->where('is_active', true)->orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'producto' => $r->product?->name, 'unit' => $r->product?->unit, 'yield' => (float) $r->yield_quantity, 'costo_unit' => $r->costoUnitario()]),
            'depositos' => Deposito::with('location:id,name')->where('activo', true)->get()->map(fn($d) => ['id' => $d->id, 'nombre' => $d->nombre, 'sucursal' => $d->location?->name]),
            'kpis' => [
                'en_curso' => ProductionOrder::where('status', 'in_progress')->count(), 'pendientes' => ProductionOrder::where('status', 'pending')->count(),
                'atrasadas' => ProductionOrder::whereIn('status', ['pending', 'in_progress'])->whereNotNull('scheduled_at')->where('scheduled_at', '<', now()->startOfDay())->count(),
                'terminadas_mes' => ProductionOrder::where('status', 'completed')->where('completed_at', '>=', $mes)->count(),
                'costo_mes' => (float) ProductionOrder::where('status', 'completed')->where('completed_at', '>=', $mes)->sum('cost'),
            ],
            'abierta' => $abierta ? $this->orden($abierta, $service, true) : null,
        ]);
    }

    private function orden(ProductionOrder $o, ProduccionService $service, bool $conInsumos = false): array
    {
        $abierta = in_array($o->status, ['pending', 'in_progress'], true);
        return [
            'id' => $o->id, 'numero' => $o->numeroFormateado(), 'estado' => $o->status, 'estado_label' => $o->estadoLabel(), 'formula' => $o->recipe?->name, 'recipe_id' => $o->recipe_id,
            'producto' => $o->product?->name, 'unit' => $o->product?->unit, 'cantidad' => (float) $o->quantity, 'producida' => $o->cantidad_producida !== null ? (float) $o->cantidad_producida : null,
            'costo' => (float) $o->cost, 'deposito' => $o->deposito?->nombre, 'usuario' => $o->user?->name, 'notas' => $o->notes,
            'programada' => $o->scheduled_at?->format('d/m/Y'), 'atrasada' => $abierta && $o->scheduled_at && $o->scheduled_at->lt(now()->startOfDay()),
            'inicio' => $o->started_at?->format('d/m/Y H:i'), 'fin' => $o->completed_at?->format('d/m/Y H:i'),
            'insumos' => $conInsumos || $abierta ? $service->insumosDe($o) : [], 'faltan' => $abierta && collect($service->insumosDe($o))->contains(fn($i) => $i['falta'] > 0),
        ];
    }

    public function crear(Request $request, ProduccionService $service)
    {
        $d = $request->validate(['recipe_id' => 'required|exists:recipes,id', 'quantity' => 'required|numeric|min:0.001', 'deposito_id' => 'nullable|exists:depositos,id', 'scheduled_at' => 'nullable|date', 'notes' => 'nullable|string|max:300']);
        $o = $service->crearOrden($d);
        return back()->with('success', "Orden {$o->numeroFormateado()} creada.");
    }

    public function iniciar(int $id, ProduccionService $service)
    {
        $service->iniciar(ProductionOrder::findOrFail($id));
        return back()->with('success', 'Orden en curso.');
    }

    public function terminar(Request $request, int $id, ProduccionService $service)
    {
        $d = $request->validate(['producida' => 'nullable|numeric|min:0']);
        $o = $service->terminar(ProductionOrder::findOrFail($id), isset($d['producida']) ? (float) $d['producida'] : null);
        return back()->with("success", "{$o->numeroFormateado()} terminada: entraron " . rtrim(rtrim(number_format((float) $o->cantidad_producida, 3, ",", "."), "0"), ",") . " {$o->product?->unit} de {$o->product?->name} al depósito.");
    }

    public function cancelar(Request $request, int $id, ProduccionService $service)
    {
        $d = $request->validate(['motivo' => 'required|string|max:200']);
        $service->cancelar(ProductionOrder::findOrFail($id), $d['motivo']);
        return back()->with('success', 'Orden cancelada.');
    }

    public function formulas()
    {
        [$productos, $prodParcial] = \App\Support\Catalogo::productos('produccion', Recipe::with('items')->get()->flatMap(fn($r) => array_merge([$r->product_id], $r->items->pluck('product_id')->all()))->all());
        return Inertia::render('Produccion/Formulas', [
            'formulas' => Recipe::with('product:id,name,unit,cost,price', 'items.product:id,name,unit,cost')->withCount('ordenes')->orderBy('name')->get()->map(fn($r) => [
                'id' => $r->id, 'name' => $r->name, 'product_id' => $r->product_id, 'producto' => $r->product?->name, 'unit' => $r->product?->unit, 'yield_quantity' => (float) $r->yield_quantity, 'yield_unit' => $r->yield_unit,
                'instructions' => $r->instructions, 'tiempo_minutos' => $r->tiempo_minutos, 'is_active' => $r->is_active, 'costo' => (float) $r->costo_calculado, 'costo_unit' => $r->costoUnitario(), 'precio' => (float) ($r->product?->price ?? 0), 'ordenes' => $r->ordenes_count,
                'items' => $r->items->map(fn($i) => ['product_id' => $i->product_id, 'nombre' => $i->product?->name, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'notes' => $i->notes, 'costo' => round((float) $i->quantity * (float) ($i->product?->cost ?? 0), 2)]),
            ]),
            'productos' => $productos, 'catalogoParcial' => ['productos' => $prodParcial],
            'unidades' => Product::UNIDADES,
        ]);
    }

    public function guardarFormula(Request $request, ProduccionService $service, ?int $id = null)
    {
        $d = $request->validate(['product_id' => 'required|exists:products,id', 'name' => 'required|string|max:120', 'yield_quantity' => 'required|numeric|min:0.001', 'yield_unit' => 'nullable|string|max:20', 'instructions' => 'nullable|string|max:2000', 'tiempo_minutos' => 'nullable|integer|min:0', 'is_active' => 'boolean', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.quantity' => 'required|numeric|min:0.0001', 'items.*.unit' => 'nullable|string|max:20', 'items.*.notes' => 'nullable|string|max:120']);
        $r = $service->guardarFormula($d, $id ? Recipe::findOrFail($id) : null);
        return back()->with('success', "Fórmula {$r->name} guardada. Costo por tanda: $ " . number_format((float) $r->costo_calculado, 2, ',', '.') . '.');
    }
}
