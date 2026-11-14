<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CentroCosto;
use App\Services\Contabilidad\CentrosCostoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 96: Contable → Centros de costo: los centros, el presupuesto de cada mes y el real que sale de gastos y compras.
class CentrosCostoController extends Controller
{
    public function index(Request $request, CentrosCostoService $svc)
    {
        $anio = (int) ($request->anio ?: now()->format('Y'));
        return Inertia::render('Contable/CentrosCosto', ['tablero' => $svc->tablero($request->user()->business, $anio), 'anios' => range((int) now()->format('Y') + 1, (int) now()->format('Y') - 3)]);
    }

    public function guardar(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:60', 'codigo' => 'nullable|string|max:20', 'activo' => 'boolean']);
        $c = $id ? CentroCosto::findOrFail($id) : new CentroCosto(['business_id' => $request->user()->business_id]);
        $c->fill($d)->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Centro de costo {$c->nombre}");
        return back()->with('success', 'Centro de costo guardado.');
    }

    public function eliminar(int $id)
    {
        $c = CentroCosto::findOrFail($id);
        $c->update(['activo' => false]); // no se borra: los gastos y compras imputados siguen apuntando a él
        return back()->with('success', "{$c->nombre} quedó inactivo. Lo imputado antes se sigue viendo.");
    }

    public function presupuesto(Request $request, CentrosCostoService $svc)
    {
        $d = $request->validate(['centro_costo_id' => 'nullable|integer|exists:centros_costo,id', 'anio' => 'required|integer|min:2000|max:2100', 'montos' => 'required|array', 'montos.*' => 'nullable|numeric|min:0']);
        $svc->guardarPresupuesto($request->user()->business, $d['centro_costo_id'] ?? null, (int) $d['anio'], $d['montos']);
        return back()->with('success', 'Presupuesto guardado.');
    }
}
