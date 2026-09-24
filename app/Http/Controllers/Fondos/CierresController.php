<?php

namespace App\Http\Controllers\Fondos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CuentaFondos;
use App\Models\MovimientoFondos;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Fondos\FondosService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

// Control de cajas: historial de cierres, diferencias por cajero y por caja, arqueos parciales y retiros a tesorería.
class CierresController extends Controller
{
    public function index(Request $request, FondosService $fondos)
    {
        $desde = $request->desde ?: today()->subDays(30)->toDateString(); $hasta = $request->hasta ?: today()->toDateString();
        $q = TurnoCaja::with('user:id,name', 'cuenta:id,nombre,business_location_id', 'cuenta.location:id,name')->whereNotNull('cierre')->whereBetween('cierre', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->when($request->caja, fn($q, $c) => $q->where('cuenta_fondos_id', $c))->when($request->cajero, fn($q, $u) => $q->where('user_id', $u))->when($request->solo_dif, fn($q) => $q->whereRaw('abs(diferencia) > 0.005'));
        $turnos = $q->orderByDesc('cierre')->get();
        $fila = function (TurnoCaja $t) {
            $mov = $t->movimientos; $ventas = (float) $mov->where('origen', 'cobro')->sum('ingreso'); $gastos = (float) $mov->whereIn('origen', ['gasto', 'pago'])->sum('egreso'); $retiros = (float) $mov->where('origen', 'transferencia')->sum('egreso');
            return ['id' => $t->id, 'caja' => $t->cuenta?->nombre, 'sucursal' => $t->cuenta?->location?->name, 'cajero' => $t->user?->name, 'user_id' => $t->user_id, 'apertura' => $t->apertura->format('d/m H:i'), 'cierre' => $t->cierre->format('d/m H:i'), 'horas' => round($t->apertura->diffInMinutes($t->cierre) / 60, 1), 'inicial' => (float) $t->saldo_inicial, 'esperado' => (float) $t->saldo_esperado, 'contado' => (float) $t->saldo_contado, 'diferencia' => (float) $t->diferencia, 'cobros' => $ventas, 'gastos' => $gastos, 'retiros' => $retiros, 'movimientos' => $mov->count(), 'arqueos' => count($t->arqueos ?? []), 'notas' => $t->notas,
                'medios' => collect($t->esperado_medios ?? [])->map(fn($e, $m) => ['medio' => $m, 'esperado' => (float) $e, 'declarado' => isset($t->rendicion[$m]) ? (float) $t->rendicion[$m] : null])->values()->all()];
        };
        $filas = $turnos->load('movimientos')->map($fila);
        if ($request->export) {
            $csv = "Caja;Sucursal;Cajero;Apertura;Cierre;Horas;Saldo inicial;Esperado;Contado;Diferencia;Cobros;Gastos;Retiros;Notas\n";
            foreach ($filas as $f) $csv .= implode(';', [$f['caja'], $f['sucursal'], $f['cajero'], $f['apertura'], $f['cierre'], $f['horas'], ...array_map(fn($v) => number_format($v, 2, ',', ''), [$f['inicial'], $f['esperado'], $f['contado'], $f['diferencia'], $f['cobros'], $f['gastos'], $f['retiros']]), str_replace(';', ',', (string) $f['notas'])]) . "\n";
            AuditLog::registrar('exportar', null, 'Exportó los cierres de caja');
            return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=cierres_caja.csv']);
        }
        $porCajero = $filas->groupBy('user_id')->map(fn($g) => ['cajero' => $g->first()['cajero'], 'turnos' => $g->count(), 'con_diferencia' => $g->filter(fn($f) => abs($f['diferencia']) > 0.005)->count(), 'faltantes' => round((float) $g->where('diferencia', '<', 0)->sum('diferencia'), 2), 'sobrantes' => round((float) $g->where('diferencia', '>', 0)->sum('diferencia'), 2), 'neto' => round((float) $g->sum('diferencia'), 2), 'cobros' => round((float) $g->sum('cobros'), 2), 'horas' => round((float) $g->sum('horas'), 1)])->sortBy('neto')->values();
        $porCaja = $filas->groupBy('caja')->map(fn($g, $caja) => ['caja' => $caja, 'turnos' => $g->count(), 'con_diferencia' => $g->filter(fn($f) => abs($f['diferencia']) > 0.005)->count(), 'neto' => round((float) $g->sum('diferencia'), 2), 'cobros' => round((float) $g->sum('cobros'), 2)])->values();
        // Serie por día para el gráfico: diferencia neta y cantidad de turnos.
        $porDia = $turnos->groupBy(fn($t) => $t->cierre->toDateString())->map(fn($g, $d) => ['fecha' => Carbon::parse($d)->format('d/m'), 'diferencia' => round((float) $g->sum('diferencia'), 2), 'turnos' => $g->count()])->sortKeys()->values();
        $abiertos = TurnoCaja::with('user:id,name', 'cuenta:id,nombre')->whereNull('cierre')->get()->map(fn($t) => ['id' => $t->id, 'caja' => $t->cuenta?->nombre, 'cajero' => $t->user?->name, 'apertura' => $t->apertura->format('d/m H:i'), 'horas' => round($t->apertura->diffInMinutes(now()) / 60, 1), 'esperado' => (float) $t->cuenta?->fresh()->saldo, 'arqueos' => collect($t->arqueos ?? [])->map(fn($a) => $a)->all(), 'largo' => $t->apertura->lt(now()->subHours(14))]);
        return Inertia::render('Fondos/Cierres', [
            'turnos' => $filas, 'porCajero' => $porCajero, 'porCaja' => $porCaja, 'porDia' => $porDia, 'abiertos' => $abiertos,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'caja' => $request->caja, 'cajero' => $request->cajero, 'solo_dif' => (bool) $request->solo_dif],
            'cajas' => CuentaFondos::where('tipo', 'caja')->orderBy('nombre')->get(['id', 'nombre']), 'cajeros' => User::where('business_id', $request->user()->business_id)->orderBy('name')->get(['id', 'name']),
            'cuentasDestino' => CuentaFondos::where('activa', true)->whereIn('tipo', ['banco', 'caja'])->where('moneda', 'ARS')->orderBy('tipo')->get(['id', 'nombre', 'tipo']),
            'kpis' => ['turnos' => $filas->count(), 'con_diferencia' => $filas->filter(fn($f) => abs($f['diferencia']) > 0.005)->count(), 'faltantes' => round((float) $filas->where('diferencia', '<', 0)->sum('diferencia'), 2), 'sobrantes' => round((float) $filas->where('diferencia', '>', 0)->sum('diferencia'), 2), 'cobros' => round((float) $filas->sum('cobros'), 2), 'promedio_horas' => $filas->count() ? round((float) $filas->avg('horas'), 1) : 0, 'abiertos' => $abiertos->count()],
        ]);
    }

    // Arqueo parcial: se cuenta el efectivo sin cerrar el turno y queda registrado (quién, cuándo, cuánto había y cuánto se contó).
    public function arqueo(Request $request, int $id)
    {
        $d = $request->validate(['contado' => 'required|numeric|min:0', 'notas' => 'nullable|string|max:200']);
        $t = TurnoCaja::findOrFail($id); abort_if($t->cierre, 422, 'El turno ya está cerrado.');
        $esperado = (float) $t->cuenta->fresh()->saldo; $dif = round((float) $d['contado'] - $esperado, 2);
        $t->update(['arqueos' => [...($t->arqueos ?? []), ['hora' => now()->format('d/m H:i'), 'usuario' => $request->user()->name, 'esperado' => $esperado, 'contado' => (float) $d['contado'], 'diferencia' => $dif, 'notas' => $d['notas'] ?? null]]]);
        AuditLog::registrar('editar', $t, "Arqueo en {$t->cuenta->nombre}: esperado $ " . number_format($esperado, 2, ',', '.') . ', contado $ ' . number_format((float) $d['contado'], 2, ',', '.') . ($dif != 0.0 ? " (diferencia $ " . number_format($dif, 2, ',', '.') . ')' : ''));
        return back()->with(abs($dif) < 0.005 ? 'success' : 'error', abs($dif) < 0.005 ? 'Arqueo correcto: la caja cierra justo.' : 'Arqueo registrado con diferencia de $ ' . number_format($dif, 2, ',', '.') . '.');
    }

    // Retiro de efectivo a tesorería o banco durante el turno (queda como transferencia entre cuentas, con el turno asociado).
    public function retiro(Request $request, int $id, FondosService $fondos)
    {
        $d = $request->validate(['monto' => 'required|numeric|gt:0', 'destino_id' => 'required|integer', 'referencia' => 'nullable|string|max:120']);
        $t = TurnoCaja::findOrFail($id); abort_if($t->cierre, 422, 'El turno ya está cerrado.');
        $fondos->transferir($t->cuenta, CuentaFondos::findOrFail($d['destino_id']), (float) $d['monto'], today()->toDateString(), $d['referencia'] ?? 'Retiro de caja');
        AuditLog::registrar('crear', $t, "Retiro de caja $ " . number_format((float) $d['monto'], 2, ',', '.') . " de {$t->cuenta->nombre}");
        return back()->with('success', 'Retiro registrado. La caja quedó con menos efectivo para rendir.');
    }
}
