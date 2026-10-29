<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Rubro;
use App\Services\Stock\PreciosMasivosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 34: Stock → Precios. El asistente en tres pasos (qué hacer, a qué artículos, cómo), cambiar datos en masa y el historial.
class PreciosMasivosController extends Controller
{
    public function __construct(private PreciosMasivosService $svc) {}

    public function index(Request $request)
    {
        return Inertia::render('Stock/Precios', [
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre', 'parent_id']),
            'proveedores' => Contact::suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'marcas' => \App\Models\Product::whereNotNull('marca')->where('marca', '!=', '')->distinct()->orderBy('marca')->pluck('marca')->take(200),
            'campos' => PreciosMasivosService::CAMPOS_DATOS, 'historial' => $this->svc->historial(),
            'ids' => array_values(array_filter(array_map('intval', (array) $request->query('ids', [])))),
            'inicial' => $request->only('que', 'rubro_id', 'proveedor_id', 'texto'),
        ]);
    }

    private function reglas(): array
    {
        return ['sel' => 'required|array', 'sel.ids' => 'nullable|array', 'sel.ids.*' => 'integer', 'sel.rubro_id' => 'nullable|integer', 'sel.proveedor_id' => 'nullable|integer', 'sel.marca' => 'nullable|string|max:80', 'sel.texto' => 'nullable|string|max:120', 'sel.moneda' => 'nullable|in:,ARS,USD', 'sel.comprados_desde' => 'nullable|date', 'sel.comprados_hasta' => 'nullable|date',
            'como' => 'required|array', 'como.campo' => 'required|in:cost,1,2,3,4,5,6', 'como.modo' => 'required|in:porcentaje,margen,exacto,monto,sobre_lista', 'como.valor' => 'required|numeric', 'como.lista_base' => 'nullable|integer|min:1|max:6', 'como.redondeo' => 'nullable|in:0,1,10,100', 'como.moneda' => 'nullable|in:,cada,ARS,USD', 'como.limite_pct' => 'nullable|numeric|min:1|max:1000', 'como.recalcular_listas' => 'boolean',
            'excluir' => 'nullable|array', 'excluir.*' => 'integer'];
    }

    public function previsualizar(Request $request) { $d = $request->validate($this->reglas()); return response()->json($this->svc->previsualizar($d['sel'], $d['como'])); }

    public function aplicar(Request $request)
    {
        $d = $request->validate($this->reglas());
        $log = $this->svc->aplicar($d['sel'], $d['como'], array_map('intval', $d['excluir'] ?? []));
        return back()->with('success', $log->descripcion . '. Queda en el historial y se puede deshacer.')->with('precios_log', $log->id);
    }

    public function contar(Request $request)
    {
        $d = $request->validate(['sel' => 'required|array', 'sel.ids' => 'nullable|array', 'sel.ids.*' => 'integer', 'sel.rubro_id' => 'nullable|integer', 'sel.proveedor_id' => 'nullable|integer', 'sel.marca' => 'nullable|string|max:80', 'sel.texto' => 'nullable|string|max:120', 'sel.moneda' => 'nullable|string|max:3', 'sel.comprados_desde' => 'nullable|date', 'sel.comprados_hasta' => 'nullable|date']);
        $q = $this->svc->consulta($d['sel']);
        return response()->json(['n' => (clone $q)->count(), 'muestra' => $q->orderBy('name')->limit(! empty($d['sel']['ids']) ? 500 : 8)->get(['id', 'name', 'sku'])->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku])]);
    }

    public function previsualizarDatos(Request $request)
    {
        $d = $request->validate(['sel' => 'required|array', 'cambios' => 'required|array']);
        return response()->json($this->svc->previsualizarDatos($d['sel'], $d['cambios']));
    }

    public function aplicarDatos(Request $request)
    {
        $d = $request->validate(['sel' => 'required|array', 'cambios' => 'required|array', 'excluir' => 'nullable|array']);
        $log = $this->svc->aplicarDatos($d['sel'], $d['cambios'], array_map('intval', $d['excluir'] ?? []));
        return back()->with('success', $log->descripcion . '. Se puede deshacer desde el historial.');
    }

    public function deshacer(Request $request, ?int $id = null)
    {
        $n = $this->svc->deshacer($id);
        return back()->with('success', "Listo: {$n} artículos volvieron a los valores anteriores.");
    }

    public function buscar(Request $request)
    {
        $q = trim((string) $request->q); if ($q === '') return response()->json([]);
        $like = \App\Support\Sql::like();
        return response()->json(\App\Models\Product::where('active', true)->where(fn($w) => $w->where('name', $like, "%{$q}%")->orWhere('sku', $like, "%{$q}%")->orWhere('barcode', $like, "%{$q}%"))->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'price', 'cost'])->map(fn($p) => ['id' => $p->id, 'nombre' => $p->name, 'sku' => $p->sku, 'precio' => (float) $p->price, 'costo' => (float) $p->cost]));
    }
}
