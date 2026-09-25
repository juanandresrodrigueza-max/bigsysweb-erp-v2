<?php

namespace App\Http\Controllers\Sueldos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CuentaFondos;
use App\Models\Empleado;
use App\Models\Liquidacion;
use App\Models\SueldoConcepto;
use App\Services\Sueldos\SueldosService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

// Sueldos: empleados, conceptos, liquidaciones (calculadas o importadas), recibos, asiento y pago.
class SueldosController extends Controller
{
    public function index(Request $request, SueldosService $svc)
    {
        $b = $request->user()->business;
        $conceptos = $svc->conceptos($b);
        $liqs = Liquidacion::withCount('items')->orderByDesc('periodo')->orderBy('tipo')->limit(24)->get();
        $ultima = $liqs->first();
        $periodo = $request->periodo ?: today()->format('Y-m');
        $enCurso = Liquidacion::where('periodo', $periodo)->where('tipo', $request->tipo ?: 'mensual')->with('items.empleado')->first();
        return Inertia::render('Sueldos/Index', [
            'empleados' => Empleado::with('location:id,name', 'conceptos')->orderBy('legajo')->get()->map(fn($e) => ['id' => $e->id, 'legajo' => $e->legajo, 'nombre' => $e->nombre, 'cuil' => $e->cuil, 'categoria' => $e->categoria, 'convenio' => $e->convenio, 'puesto' => $e->puesto, 'fecha_ingreso' => $e->fecha_ingreso->toDateString(), 'antiguedad' => $e->antiguedadAnios(), 'sueldo_basico' => (float) $e->sueldo_basico, 'modalidad' => $e->modalidad, 'obra_social' => $e->obra_social, 'cbu' => $e->cbu, 'email' => $e->email, 'telefono' => $e->telefono, 'activo' => $e->activo, 'documento' => $e->documento, 'centro_costo' => $e->centro_costo, 'lugar_trabajo' => $e->lugar_trabajo, 'jornada' => (string) (float) ($e->jornada ?: 1), 'asignados' => $e->conceptos->map(fn($c) => ['id' => $c->id, 'valor' => $c->pivot->valor !== null ? (float) $c->pivot->valor : null])->values(), 'sucursal' => $e->location?->name, 'business_location_id' => $e->business_location_id, 'anticipos' => $svc->anticiposPendientes($e)]),
            'conceptos' => $conceptos->map(fn($c) => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'modo' => $c->modo, 'valor' => (float) $c->valor, 'base' => $c->base, 'orden' => $c->orden, 'cantidad' => $c->cantidad, 'por_anio' => (bool) $c->por_anio, 'mas_antiguedad' => (float) $c->mas_antiguedad, 'proporcional_jornada' => (bool) $c->proporcional_jornada, 'jornada_completa' => (bool) $c->jornada_completa, 'con_detraccion' => (bool) $c->con_detraccion, 'grupo' => $c->grupo, 'solo_asignados' => (bool) $c->solo_asignados, 'etiqueta' => $c->etiqueta]),
            'tiposConcepto' => SueldoConcepto::TIPOS, 'modos' => SueldoConcepto::MODOS, 'cantidades' => SueldoConcepto::CANTIDADES, 'grupos' => SueldoConcepto::GRUPOS, 'plantillas' => SueldoConcepto::PLANTILLAS, 'jornadas' => Empleado::JORNADAS, 'tiposLiq' => Liquidacion::TIPOS, 'estados' => Liquidacion::ESTADOS,
            'liquidaciones' => $liqs->map(fn($l) => $this->resumirLiq($l)),
            'periodo' => $periodo, 'tipo' => $request->tipo ?: 'mensual',
            'enCurso' => $enCurso ? $this->detalleLiq($enCurso) : null,
            'cuentas' => CuentaFondos::where('activa', true)->whereIn('tipo', ['caja', 'banco'])->where('moneda', 'ARS')->orderBy('tipo')->get(['id', 'nombre', 'tipo', 'saldo']),
            'kpis' => ['activos' => Empleado::where('activo', true)->count(), 'masa' => (float) Empleado::where('activo', true)->sum('sueldo_basico'), 'ultimo_neto' => (float) ($ultima?->total_neto ?? 0), 'ultimo_costo' => (float) (($ultima?->total_bruto ?? 0) + ($ultima?->total_no_rem ?? 0) + ($ultima?->total_contribuciones ?? 0)), 'ultimo_periodo' => $ultima?->periodoLabel(), 'pendientes' => Liquidacion::where('estado', 'confirmada')->count()],
            'config' => $svc->config($b),
        ]);
    }

    private function resumirLiq(Liquidacion $l): array
    {
        return ['id' => $l->id, 'periodo' => $l->periodo, 'label' => $l->periodoLabel(), 'tipo' => $l->tipo, 'estado' => $l->estado, 'fecha' => $l->fecha->format('d/m/Y'), 'recibos' => $l->items_count ?? $l->items()->count(), 'bruto' => (float) $l->total_bruto, 'no_rem' => (float) $l->total_no_rem, 'deducciones' => (float) $l->total_deducciones, 'neto' => (float) $l->total_neto, 'contribuciones' => (float) $l->total_contribuciones, 'costo' => round((float) $l->total_bruto + (float) $l->total_no_rem + (float) $l->total_contribuciones, 2), 'importada' => $l->importada, 'asiento_id' => $l->asiento_id, 'pagada_en' => $l->pagada_en?->format('d/m/Y'), 'notas' => $l->notas, 'deposito_fecha' => $l->deposito_fecha?->toDateString(), 'deposito_banco' => $l->deposito_banco, 'deposito_periodo' => $l->deposito_periodo];
    }

    private function detalleLiq(Liquidacion $l): array
    {
        return $this->resumirLiq($l) + ['items' => $l->items->map(fn($i) => ['id' => $i->id, 'empleado_id' => $i->empleado_id, 'empleado' => $i->empleado->nombre, 'legajo' => $i->empleado->legajo, 'dias' => $i->dias, 'feriados' => (int) $i->feriados, 'vacaciones' => (int) $i->vacaciones, 'redondeo' => (float) $i->redondeo, 'horas_extra_50' => (float) $i->horas_extra_50, 'horas_extra_100' => (float) $i->horas_extra_100, 'adicionales' => (float) $i->adicionales, 'no_rem_extra' => (float) $i->no_rem_extra, 'anticipos' => (float) $i->anticipos, 'bruto' => (float) $i->bruto, 'no_rem' => (float) $i->no_rem, 'deducciones' => (float) $i->deducciones, 'neto' => (float) $i->neto, 'contribuciones' => (float) $i->contribuciones, 'detalle' => $i->detalle])->values()];
    }

    public function guardarEmpleado(Request $request, ?int $id = null)
    {
        $d = $request->validate(['nombre' => 'required|string|max:120', 'cuil' => 'nullable|string|max:20', 'categoria' => 'nullable|string|max:80', 'convenio' => 'nullable|string|max:80', 'puesto' => 'nullable|string|max:80', 'fecha_ingreso' => 'required|date', 'fecha_egreso' => 'nullable|date', 'sueldo_basico' => 'required|numeric|min:0', 'modalidad' => 'required|in:mensual,jornal', 'obra_social' => 'nullable|string|max:80', 'cbu' => 'nullable|string|max:30', 'email' => 'nullable|email|max:120', 'telefono' => 'nullable|string|max:40', 'activo' => 'boolean', 'business_location_id' => 'nullable|integer', 'notas' => 'nullable|string|max:500', 'documento' => 'nullable|string|max:20', 'centro_costo' => 'nullable|string|max:60', 'lugar_trabajo' => 'nullable|string|max:60', 'jornada' => 'nullable|numeric|gt:0|max:1', 'asignados' => 'nullable|array', 'asignados.*.id' => 'required|integer', 'asignados.*.valor' => 'nullable|numeric']);
        $u = $request->user();
        $asignados = $d['asignados'] ?? null; unset($d['asignados']);
        $d['jornada'] = $d['jornada'] ?? 1;
        $e = $id ? Empleado::findOrFail($id) : new Empleado(['business_id' => $u->business_id, 'legajo' => (int) Empleado::withoutGlobalScopes()->where('business_id', $u->business_id)->max('legajo') + 1]);
        $e->fill($d + ['business_location_id' => $d['business_location_id'] ?? $u->current_location_id])->save();
        // Conceptos asignados (los "solo asignados", como el CEC) y valores propios del empleado que pisan al del concepto.
        if ($asignados !== null) $e->conceptos()->sync(collect($asignados)->filter(fn($a) => SueldoConcepto::whereKey($a['id'])->exists())->mapWithKeys(fn($a) => [$a['id'] => ['valor' => $a['valor'] ?? null]])->all());
        AuditLog::registrar($id ? 'editar' : 'crear', $e, "Empleado legajo {$e->legajo} · {$e->nombre}");
        return back()->with('success', $id ? 'Empleado actualizado.' : "Empleado dado de alta con legajo {$e->legajo}.");
    }

    public function guardarConcepto(Request $request, ?int $id = null)
    {
        $d = $request->validate(['codigo' => 'required|string|max:20', 'nombre' => 'required|string|max:100', 'tipo' => 'required|in:' . implode(',', array_keys(SueldoConcepto::TIPOS)), 'modo' => 'required|in:' . implode(',', array_keys(SueldoConcepto::MODOS)), 'valor' => 'required|numeric|min:0', 'base' => ['nullable', 'string', 'max:120', 'regex:/^\s*[A-Za-z0-9_]+(\s*\+\s*[A-Za-z0-9_]+)*\s*$/'], 'orden' => 'nullable|integer',
            'cantidad' => 'nullable|in:' . implode(',', array_filter(array_keys(SueldoConcepto::CANTIDADES))), 'por_anio' => 'boolean', 'mas_antiguedad' => 'nullable|numeric|min:0', 'proporcional_jornada' => 'boolean', 'jornada_completa' => 'boolean', 'con_detraccion' => 'boolean', 'grupo' => 'nullable|in:' . implode(',', array_keys(SueldoConcepto::GRUPOS)), 'solo_asignados' => 'boolean', 'etiqueta' => 'nullable|string|max:30']);
        $d['base'] = strtoupper(trim($d['base'] ?? '')) ?: 'BASICO'; $d['mas_antiguedad'] = $d['mas_antiguedad'] ?? 0; $d['cantidad'] = $d['cantidad'] ?: null;
        $c = $id ? SueldoConcepto::findOrFail($id) : new SueldoConcepto(['business_id' => $request->user()->business_id]);
        $c->fill($d)->save();
        return back()->with('success', 'Concepto guardado.');
    }

    public function borrarConcepto(int $id)
    {
        SueldoConcepto::findOrFail($id)->update(['activo' => false]);
        return back()->with('success', 'Concepto desactivado.');
    }

    // Carga una plantilla de convenio: reemplaza los conceptos activos y deja el redondeo al peso como en los recibos del estudio.
    public function plantilla(Request $request, string $plantilla, SueldosService $svc)
    {
        abort_unless(isset(SueldoConcepto::PLANTILLAS[$plantilla]), 404);
        $b = $request->user()->business;
        $n = SueldoConcepto::cargarPlantilla($b->id, $plantilla);
        $cfg = $svc->config($b);
        $b->update(['sueldos' => array_replace($cfg, ['redondeo' => 'peso', 'codigo_redondeo' => '3950', 'convenio' => $cfg['convenio'] ?: 'COMERCIO CCT N° 130/75', 'obra_social' => $cfg['obra_social'] ?: 'OSECAC - COMERCIO'])]);
        AuditLog::registrar('editar', $b, 'Cargó la plantilla de sueldos ' . SueldoConcepto::PLANTILLAS[$plantilla]);
        return back()->with('success', "Plantilla cargada: {$n} conceptos. Asigná el CEC a los empleados que corresponda.");
    }

    public function guardarConfig(Request $request, SueldosService $svc)
    {
        $d = $request->validate(['dia_pago' => 'required|integer|min:1|max:28', 'cuenta_id' => 'nullable|integer', 'detraccion' => 'nullable|numeric|min:0', 'redondeo' => 'nullable|in:no,peso', 'codigo_redondeo' => 'nullable|string|max:10', 'actividad' => 'nullable|string|max:120', 'convenio' => 'nullable|string|max:80', 'obra_social' => 'nullable|string|max:80', 'lugar_pago' => 'nullable|string|max:120', 'deposito_banco' => 'nullable|string|max:60']);
        $b = $request->user()->business;
        $b->update(['sueldos' => array_replace($svc->config($b), array_map(fn($v) => $v ?? '', $d), ['cuenta_id' => $d['cuenta_id'] ?? null, 'detraccion' => (float) ($d['detraccion'] ?? SueldosService::DETRACCION)])]);
        return back()->with('success', 'Configuración guardada.');
    }

    public function liquidar(Request $request, SueldosService $svc)
    {
        $d = $request->validate(['periodo' => 'required|date_format:Y-m', 'tipo' => 'required|in:mensual,sac,final', 'fecha' => 'nullable|date', 'novedades' => 'nullable|array']);
        $liq = $svc->liquidar($request->user()->business, $d['periodo'], $d['tipo'], $request->input('novedades', []), $d['fecha'] ?? null);
        return redirect("/sueldos?periodo={$liq->periodo}&tipo={$liq->tipo}")->with('success', "Liquidación {$liq->periodoLabel()} calculada: {$liq->items()->count()} recibos, neto $ " . number_format((float) $liq->total_neto, 2, ',', '.') . '.');
    }

    public function importar(Request $request, SueldosService $svc)
    {
        $d = $request->validate(['periodo' => 'required|date_format:Y-m', 'tipo' => 'required|in:mensual,sac,final', 'csv' => 'nullable|string', 'archivo' => 'nullable|file|max:4096']);
        $csv = $request->hasFile('archivo') ? file_get_contents($request->file('archivo')->getRealPath()) : (string) ($d['csv'] ?? '');
        abort_if(trim($csv) === '', 422, 'Pegá el contenido o subí el archivo.');
        $liq = $svc->importar($request->user()->business, $d['periodo'], $d['tipo'], $csv);
        return redirect("/sueldos?periodo={$liq->periodo}&tipo={$liq->tipo}")->with('success', "Importada la liquidación {$liq->periodoLabel()}: {$liq->items()->count()} recibos.");
    }

    // Último depósito de aportes que se informa en el recibo (art. 140 LCT, ley 17.250).
    public function deposito(Request $request, int $id)
    {
        $d = $request->validate(['deposito_fecha' => 'nullable|date', 'deposito_banco' => 'nullable|string|max:60', 'deposito_periodo' => 'nullable|date_format:Y-m']);
        Liquidacion::findOrFail($id)->forceFill($d)->save();
        return back()->with('success', 'Datos del último depósito guardados.');
    }

    public function confirmar(int $id, SueldosService $svc)
    {
        $liq = $svc->confirmar(Liquidacion::findOrFail($id));
        return back()->with('success', "Liquidación {$liq->periodoLabel()} confirmada y contabilizada.");
    }

    public function reabrir(int $id, SueldosService $svc)
    {
        $svc->reabrir(Liquidacion::findOrFail($id));
        return back()->with('success', 'Liquidación reabierta: volvió a borrador.');
    }

    public function pagar(Request $request, int $id, SueldosService $svc)
    {
        $d = $request->validate(['cuenta_id' => 'required|integer', 'fecha' => 'required|date', 'cargas' => 'boolean']);
        $liq = Liquidacion::findOrFail($id);
        $svc->pagar($liq, CuentaFondos::findOrFail($d['cuenta_id']), $d['fecha'], (bool) ($d['cargas'] ?? false));
        return back()->with('success', 'Sueldos pagados: $ ' . number_format((float) $liq->total_neto, 2, ',', '.') . ($d['cargas'] ?? false ? ' más las cargas sociales.' : '.'));
    }

    public function pagarCargas(Request $request, int $id, SueldosService $svc)
    {
        $d = $request->validate(['cuenta_id' => 'required|integer', 'fecha' => 'required|date']);
        $svc->pagarCargas(Liquidacion::findOrFail($id), CuentaFondos::findOrFail($d['cuenta_id']), $d['fecha']);
        return back()->with('success', 'Cargas sociales pagadas.');
    }

    public function anticipo(Request $request, int $id, SueldosService $svc)
    {
        $d = $request->validate(['cuenta_id' => 'required|integer', 'monto' => 'required|numeric|gt:0', 'fecha' => 'required|date']);
        $svc->anticipo(Empleado::findOrFail($id), CuentaFondos::findOrFail($d['cuenta_id']), (float) $d['monto'], $d['fecha']);
        return back()->with('success', 'Anticipo registrado. Se descuenta en la próxima liquidación.');
    }

    public function recibo(Request $request, int $id, ?int $item = null)
    {
        $liq = Liquidacion::with('items.empleado')->findOrFail($id);
        $items = $item ? $liq->items->where('id', $item) : $liq->items;
        $b = $request->user()->business;
        return view('sueldos.recibo', ['liq' => $liq, 'items' => $items, 'b' => $b, 'cfg' => app(SueldosService::class)->config($b), 'marca' => $b->marcaImpresion()]);
    }

    public function f931(int $id, SueldosService $svc)
    {
        $liq = Liquidacion::findOrFail($id);
        AuditLog::registrar('exportar', $liq, "Exportó el resumen F.931 de {$liq->periodoLabel()}");
        return response("\xEF\xBB\xBF" . $svc->resumen931($liq), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=f931_{$liq->periodo}_{$liq->tipo}.csv"]);
    }

    public function libro(int $id, SueldosService $svc)
    {
        $liq = Liquidacion::findOrFail($id);
        AuditLog::registrar('exportar', $liq, "Exportó libro de sueldos {$liq->periodoLabel()}");
        return response("\xEF\xBB\xBF" . $svc->libroCsv($liq), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=sueldos_{$liq->periodo}_{$liq->tipo}.csv"]);
    }
}
