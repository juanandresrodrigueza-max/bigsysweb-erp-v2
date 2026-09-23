<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Models\TurnoCaja;
use App\Services\Fondos\FondosService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FondosController extends Controller
{
    public function __construct(private FondosService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $cuentas = CuentaFondos::with(['location:id,name', 'turnoAbierto.user:id,name'])->orderByDesc('activa')->orderBy('tipo')->orderBy('nombre')->get();
        $cuentaId = (int) $request->input('cuenta') ?: $cuentas->first(fn($c) => $c->activa)?->id;

        $movs = $cuentaId ? MovimientoFondos::where('cuenta_fondos_id', $cuentaId)->with(['user:id,name', 'categoria:id,name'])
            ->when($request->desde, fn($q, $d) => $q->where('fecha', '>=', d))
            ->when($request->hasta, fn($q, $h) => $q->where('fecha', '<=', h))
            ->orderByDesc('fecha')->orderByDesc('id')->paginate(40)->withQueryString()
            ->through(fn($m) => ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'origen' => $m->origen, 'concepto' => $m->concepto, 'categoria' => $m->categoria?->name, 'ingreso' => (float) $m->ingreso, 'egreso' => (float) $m->egreso, 'referencia' => $m->referencia, 'usuario' => $m->user?->name, 'conciliado' => $m->conciliado, 'origen_id' => $m->origen_id]) : null;

        $hoy = today();
        $chequesCartera = Cheque::enCartera()->sum('monto');
        $chequesPropios = Cheque::propiosPendientes()->sum('monto');

        return Inertia::render('Fondos/Index', [
            'cuentas' => $cuentas->map(fn($c) => ['id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombre, 'banco' => $c->banco, 'cbu' => $c->cbu, 'alias' => $c->alias, 'saldo' => (float) $c->saldo, 'saldo_minimo' => (float) $c->saldo_minimo, 'activa' => $c->activa, 'es_default' => $c->es_default, 'business_location_id' => $c->business_location_id, 'sucursal' => $c->location?->name,
                'turno' => $c->turnoAbierto ? ['id' => $c->turnoAbierto->id, 'usuario' => $c->turnoAbierto->user?->name, 'desde' => $c->turnoAbierto->apertura->format('d/m H:i'), 'saldo_inicial' => (float) $c->turnoAbierto->saldo_inicial, 'esperado' => $this->service->esperadoPorMedio($c->turnoAbierto)] : null]),
            'turnosCerrados' => TurnoCaja::whereNotNull('cierre')->with('user:id,name', 'cuenta:id,nombre')->orderByDesc('cierre')->limit(8)->get()->map(fn($t) => ['id' => $t->id, 'caja' => $t->cuenta?->nombre, 'usuario' => $t->user?->name, 'apertura' => $t->apertura->format('d/m H:i'), 'cierre' => $t->cierre->format('d/m H:i'), 'esperado' => (float) $t->saldo_esperado, 'contado' => (float) $t->saldo_contado, 'diferencia' => (float) $t->diferencia]),
            'cuentaActual' => $cuentaId, 'movimientos' => $movs, 'filtros' => $request->only('cuenta', 'desde', 'hasta'),
            'totales' => ['disponible' => (float) $cuentas->where('activa', true)->sum('saldo'), 'cheques_cartera' => (float) $chequesCartera, 'cheques_propios' => (float) $chequesPropios,
                'ingresos_mes' => (float) MovimientoFondos::whereMonth('fecha', $hoy->month)->whereYear('fecha', $hoy->year)->whereNotIn('origen', ['transferencia', 'apertura'])->sum('ingreso'),
                'egresos_mes' => (float) MovimientoFondos::whereMonth('fecha', $hoy->month)->whereYear('fecha', $hoy->year)->whereNotIn('origen', ['transferencia', 'apertura'])->sum('egreso')],
            'categorias' => ExpenseCategory::orderBy('name')->get(['id', 'name', 'color']),
            'listaSucursales' => $user->business->locations()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tipos' => CuentaFondos::TIPOS,
        ]);
    }

    public function guardarCuenta(Request $request, ?int $id = null)
    {
        $b = $request->user()->business;
        $data = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(CuentaFondos::TIPOS))], 'nombre' => 'required|string|max:80', 'banco' => 'nullable|string|max:60', 'cbu' => 'nullable|string|max:30', 'alias' => 'nullable|string|max:40',
            'saldo_minimo' => 'nullable|numeric|min:0', 'activa' => 'boolean', 'es_default' => 'boolean', 'business_location_id' => ['nullable', Rule::exists('business_locations', 'id')->where('business_id', $b->id)], 'saldo_inicial' => 'nullable|numeric',
        ]);
        $c = $id ? CuentaFondos::findOrFail($id) : new CuentaFondos(['business_id' => $b->id]);
        $c->fill(collect($data)->except('saldo_inicial')->all() + ['activa' => $data['activa'] ?? true])->save();
        if (! $id && (float) ($data['saldo_inicial'] ?? 0) != 0) {
            $this->service->registrar($c, ['origen' => 'ajuste', 'concepto' => 'Saldo inicial', 'ingreso' => max(0, (float) $data['saldo_inicial']), 'egreso' => max(0, -(float) $data['saldo_inicial'])]);
        }
        if ($data['es_default'] ?? false) {
            CuentaFondos::where('tipo', $c->tipo)->where('id', '!=', $c->id)->update(['es_default' => false]);
        }
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Cuenta de fondos {$c->nombre}");
        return back()->with('success', 'Cuenta guardada.');
    }

    public function movimiento(Request $request)
    {
        $data = $request->validate(['cuenta_fondos_id' => 'required|integer', 'fecha' => 'required|date', 'tipo' => 'required|in:ingreso,egreso', 'monto' => 'required|numeric|gt:0', 'concepto' => 'required|string|max:255', 'expense_category_id' => 'nullable|integer', 'referencia' => 'nullable|string|max:120']);
        $cuenta = CuentaFondos::findOrFail($data['cuenta_fondos_id']);
        $m = $this->service->registrar($cuenta, ['fecha' => $data['fecha'], 'origen' => $data['tipo'] === 'egreso' ? 'gasto' : 'ingreso', 'expense_category_id' => $data['expense_category_id'] ?? null, 'concepto' => $data['concepto'], $data['tipo'] => $data['monto'], 'referencia' => $data['referencia'] ?? null]);
        AuditLog::registrar('crear', $m, ucfirst($data['tipo']) . " manual en {$cuenta->nombre}: {$data['concepto']} $ " . number_format((float) $data['monto'], 2, ',', '.'));
        return back()->with('success', 'Movimiento registrado.');
    }

    public function transferir(Request $request)
    {
        $data = $request->validate(['desde' => 'required|integer', 'hasta' => 'required|integer', 'monto' => 'required|numeric|gt:0', 'fecha' => 'required|date', 'referencia' => 'nullable|string|max:120']);
        $this->service->transferir(CuentaFondos::findOrFail($data['desde']), CuentaFondos::findOrFail($data['hasta']), (float) $data['monto'], $data['fecha'], $data['referencia'] ?? null);
        return back()->with('success', 'Transferencia registrada.');
    }

    public function abrirTurno(Request $request, int $id)
    {
        $data = $request->validate(['saldo_inicial' => 'required|numeric|min:0']);
        $this->service->abrirTurno(CuentaFondos::findOrFail($id), (float) $data['saldo_inicial']);
        return back()->with('success', 'Turno abierto.');
    }

    public function cerrarTurno(Request $request, int $id)
    {
        $data = $request->validate(['saldo_contado' => 'required|numeric|min:0', 'notas' => 'nullable|string|max:500', 'rendicion' => 'nullable|array']);
        $t = $this->service->cerrarTurno(TurnoCaja::findOrFail($id), (float) $data['saldo_contado'], $data['notas'] ?? null, $data['rendicion'] ?? []);
        $dif = (float) $t->diferencia;
        return back()->with($dif == 0.0 ? 'success' : 'error', 'Turno cerrado. ' . ($dif == 0.0 ? 'Sin diferencias.' : 'Diferencia: $ ' . number_format($dif, 2, ',', '.')));
    }

    public function rendicion(int $id)
    {
        $t = TurnoCaja::with(['cuenta.location', 'user', 'business'])->findOrFail($id);
        abort_if(! $t->cierre, 422, 'El turno todavía está abierto.');
        $movs = MovimientoFondos::where('turno_caja_id', $t->id)->orderBy('id')->get();
        $medios = array_merge(\App\Models\Cobro::MEDIOS, ['cta_cte' => 'Cuenta corriente']);
        return view('fondos.rendicion', ['t' => $t, 'b' => $t->business, 'movs' => $movs, 'medios' => $medios]);
    }

    public function guardarCategoria(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'color' => 'nullable|string|max:10', 'tipo_costo' => 'nullable|in:fijo,variable', 'imputacion' => 'nullable|in:directo,indirecto']);
        $data = array_filter($data, fn($v) => $v !== null) + ExpenseCategory::sugerir($data['name']);
        ExpenseCategory::create($data + ['business_id' => $request->user()->business_id]);
        return back()->with('success', 'Categoría creada.');
    }
}
