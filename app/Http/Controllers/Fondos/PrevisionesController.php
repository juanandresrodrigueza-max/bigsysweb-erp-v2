<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\Prevision;
use App\Services\Fondos\PrevisionesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Previsiones: lo que se paga o se cobra todos los meses o cada X meses.
class PrevisionesController extends Controller
{
    public function index(Request $request)
    {
        $items = Prevision::with(['categoria:id,name', 'contact:id,name', 'cuenta:id,nombre'])->orderBy('activo', 'desc')->orderBy('proximo')->get();
        $hoy = today(); $fin = $hoy->copy()->addMonths(12);
        $mes = fn($tipo) => round((float) $items->where('activo', true)->where('tipo', $tipo)->sum(fn($p) => (float) $p->monto / max(1, (int) $p->cada_meses)), 2);
        $calendario = [];
        for ($i = 0; $i < 12; $i++) { $m = $hoy->copy()->startOfMonth()->addMonths($i); $calendario[$m->format('Y-m')] = ['label' => $m->translatedFormat('M y'), 'egresos' => 0, 'ingresos' => 0]; }
        foreach ($items->where('activo', true) as $p) foreach ($p->vencimientosEntre($hoy->copy()->startOfMonth(), $fin) as $v) { $k = $v->format('Y-m'); if (isset($calendario[$k])) $calendario[$k][$p->tipo === 'ingreso' ? 'ingresos' : 'egresos'] += (float) $p->monto; }
        return Inertia::render('Fondos/Previsiones', [
            'items' => $items->map(fn($p) => ['id' => $p->id, 'tipo' => $p->tipo, 'descripcion' => $p->descripcion, 'monto' => (float) $p->monto, 'cada_meses' => (int) $p->cada_meses, 'frecuencia' => $p->frecuenciaLabel(), 'dia' => (int) $p->dia,
                'desde' => $p->desde->toDateString(), 'hasta' => $p->hasta?->toDateString(), 'proximo' => $p->proximo?->format('d/m/Y'), 'dias' => $p->proximo ? (int) today()->diffInDays($p->proximo, false) : null,
                'expense_category_id' => $p->expense_category_id, 'categoria' => $p->categoria?->name, 'contact_id' => $p->contact_id, 'contacto' => $p->contact?->name, 'cuenta_fondos_id' => $p->cuenta_fondos_id, 'cuenta' => $p->cuenta?->nombre,
                'registrar_auto' => $p->registrar_auto, 'avisar_dias' => (int) $p->avisar_dias, 'activo' => $p->activo, 'notas' => $p->notas, 'ultimo' => $p->ultimo_registrado_en?->format('d/m/Y'), 'registradas' => $p->movimientos()->count()]),
            'kpis' => ['egresos_mes' => $mes('egreso'), 'ingresos_mes' => $mes('ingreso'), 'vencen_7' => $items->where('activo', true)->filter(fn($p) => $p->proximo && $p->proximo->lte($hoy->copy()->addDays(7)))->count(), 'activas' => $items->where('activo', true)->count()],
            'calendario' => array_values(array_map(fn($c) => $c + ['egresos' => round($c['egresos'], 2), 'ingresos' => round($c['ingresos'], 2)], $calendario)),
            'frecuencias' => Prevision::FRECUENCIAS,
            'categorias' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'cuentas' => CuentaFondos::where('activa', true)->orderByDesc('es_default')->get(['id', 'nombre', 'tipo']),
            'contactos' => Contact::where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name', 'type']),
        ]);
    }

    public function guardar(Request $request, PrevisionesService $svc, ?int $id = null)
    {
        $d = $request->validate([
            'tipo' => 'required|in:egreso,ingreso', 'descripcion' => 'required|string|max:160', 'monto' => 'required|numeric|gt:0',
            'expense_category_id' => 'nullable|integer|exists:expense_categories,id', 'contact_id' => 'nullable|integer|exists:contacts,id', 'cuenta_fondos_id' => 'nullable|integer|exists:cuentas_fondos,id',
            'cada_meses' => 'required|integer|min:1|max:60', 'dia' => 'required|integer|min:1|max:31', 'desde' => 'required|date', 'hasta' => 'nullable|date|after_or_equal:desde',
            'registrar_auto' => 'boolean', 'avisar_dias' => 'nullable|integer|min:0|max:60', 'activo' => 'boolean', 'notas' => 'nullable|string|max:1000',
        ]);
        $p = $svc->guardar($d + ['avisar_dias' => $d['avisar_dias'] ?? 3], $id ? Prevision::findOrFail($id) : null);
        AuditLog::registrar($id ? 'editar' : 'crear', $p, "Previsión {$p->descripcion} $ " . number_format((float) $p->monto, 2, ',', '.') . ' ' . strtolower($p->frecuenciaLabel()));
        return back()->with('success', $id ? 'Previsión actualizada.' : 'Previsión creada. Próximo vencimiento: ' . ($p->proximo?->format('d/m/Y') ?? 'sin fecha'));
    }

    public function registrar(Request $request, int $id, PrevisionesService $svc)
    {
        $d = $request->validate(['cuenta_fondos_id' => 'nullable|integer|exists:cuentas_fondos,id', 'fecha' => 'nullable|date', 'monto' => 'nullable|numeric|gt:0']);
        $p = Prevision::findOrFail($id);
        $m = $svc->registrar($p, $d['cuenta_fondos_id'] ?? null, $d['fecha'] ?? null, isset($d['monto']) ? (float) $d['monto'] : null);
        return back()->with('success', ($p->tipo === 'ingreso' ? 'Ingreso' : 'Gasto') . " registrado: {$p->descripcion} $ " . number_format((float) $m->egreso + (float) $m->ingreso, 2, ',', '.') . '. Próximo: ' . ($p->fresh()->proximo?->format('d/m/Y') ?? 'terminó'));
    }

    public function eliminar(int $id)
    {
        $p = Prevision::findOrFail($id);
        AuditLog::registrar('eliminar', $p, "Eliminó la previsión {$p->descripcion}");
        $p->delete();
        return back()->with('success', 'Previsión eliminada.');
    }
}
