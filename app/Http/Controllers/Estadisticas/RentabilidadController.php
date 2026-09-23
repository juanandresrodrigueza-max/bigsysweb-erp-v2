<?php

namespace App\Http\Controllers\Estadisticas;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Services\Estadisticas\RentabilidadService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

// Rentabilidad real del negocio: margen bruto, de contribución y resultado, con costos fijos/variables y directos/indirectos.
class RentabilidadController extends Controller
{
    public function __construct(private RentabilidadService $svc) {}

    public function index(Request $request)
    {
        $desde = $request->desde ? Carbon::parse($request->desde) : now()->startOfMonth();
        $hasta = $request->hasta ? Carbon::parse($request->hasta) : now()->endOfMonth();
        $sucursal = $request->sucursal ? (int) $request->sucursal : null;
        $r = $this->svc->calcular($request->user()->business, $desde, $hasta, $sucursal, $request->export ? RentabilidadService::DIMENSIONES : ['articulos']);
        if ($request->export) {
            AuditLog::registrar('exportar', null, "Exportó rentabilidad {$desde->toDateString()} a {$hasta->toDateString()}");
            return response($this->svc->csv($r, $desde->toDateString(), $hasta->toDateString()), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=rentabilidad_{$desde->toDateString()}_{$hasta->toDateString()}.csv"]);
        }
        return Inertia::render('Estadisticas/Rentabilidad', $r + [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()], 'sucursalId' => $sucursal,
            'listaSucursales' => $request->user()->business->locations()->orderBy('name')->get(['id', 'name']),
            'tipos' => ExpenseCategory::TIPOS, 'imputaciones' => ExpenseCategory::IMPUTACIONES,
        ]);
    }

    // Una apertura (por rubro, cliente, vendedor, sucursal u obra) a pedido, en JSON: la página las carga al abrir cada solapa.
    public function dimension(Request $request)
    {
        $dim = $request->input('dim'); abort_unless(in_array($dim, RentabilidadService::DIMENSIONES, true), 404);
        $desde = $request->desde ? Carbon::parse($request->desde) : now()->startOfMonth();
        $hasta = $request->hasta ? Carbon::parse($request->hasta) : now()->endOfMonth();
        $r = $this->svc->calcular($request->user()->business, $desde, $hasta, $request->sucursal ? (int) $request->sucursal : null, [$dim], false);
        return response()->json($r['por'][$dim]);
    }

    // Clasifica las categorías de gasto (fijo/variable, directo/indirecto) de una vez.
    public function clasificar(Request $request)
    {
        $data = $request->validate(['categorias' => 'required|array', 'categorias.*.id' => 'required|integer', 'categorias.*.tipo_costo' => 'required|in:fijo,variable', 'categorias.*.imputacion' => 'required|in:directo,indirecto']);
        foreach ($data['categorias'] as $c) ExpenseCategory::where('id', $c['id'])->update(['tipo_costo' => $c['tipo_costo'], 'imputacion' => $c['imputacion']]);
        AuditLog::registrar('editar', null, 'Clasificó categorías de gasto para rentabilidad');
        return back()->with('success', 'Categorías clasificadas.');
    }

    public function configurar(Request $request)
    {
        $data = $request->validate(['distribuir_indirectos' => 'required|boolean', 'compras_gastos' => 'required|in:fijo,variable', 'sin_categoria' => 'required|in:fijo,variable']);
        $b = $request->user()->business;
        $b->update(['rentabilidad' => array_merge($b->rentabilidad ?? [], $data)]);
        return back()->with('success', 'Configuración de rentabilidad guardada.');
    }
}
