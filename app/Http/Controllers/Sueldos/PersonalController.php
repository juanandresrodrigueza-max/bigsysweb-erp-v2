<?php

namespace App\Http\Controllers\Sueldos;

use App\Http\Controllers\Controller;
use App\Models\Ausencia;
use App\Models\Empleado;
use App\Models\Feriado;
use App\Services\Personal\AusenciasService;
use App\Services\Personal\FeriadosService;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 98: Sueldos → Ausencias y vacaciones: licencias, vacaciones por antigüedad (LCT) y el calendario de feriados de la empresa.
class PersonalController extends Controller
{
    public function __construct(private AusenciasService $aus, private FeriadosService $fer) {}

    public function index(Request $request)
    {
        $b = $request->user()->business;
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->mes) ? $request->mes : today()->format('Y-m');
        $anio = (int) ($request->anio ?: today()->year);
        $hoy = $this->aus->hoy($b);
        return Inertia::render('Sueldos/Personal', [
            'mes' => $mes, 'anio' => $anio,
            'empleados' => Empleado::where('activo', true)->orderBy('legajo')->get(['id', 'legajo', 'nombre'])->map(fn($e) => ['id' => $e->id, 'legajo' => $e->legajo, 'nombre' => $e->nombre]),
            'ausencias' => $this->aus->delMes($b, $mes),
            'pendientes' => Ausencia::with('empleado:id,nombre,legajo')->where('estado', 'pendiente')->orderBy('desde')->get()->map(fn($a) => $this->aus->arr($a))->all(),
            'vacaciones' => $this->aus->tableroVacaciones($b, $anio),
            'feriados' => $this->fer->listado($b, $anio),
            'tipos' => collect(Ausencia::TIPOS)->map(fn($t, $k) => ['key' => $k] + $t)->values(), 'estados' => Ausencia::ESTADOS, 'tiposFeriado' => Feriado::TIPOS,
            'kpis' => ['hoy' => count($hoy), 'hoy_nombres' => collect($hoy)->pluck('empleado')->all(), 'proximas' => count($this->aus->proximasVacaciones($b, 30)), 'saldo_total' => round(collect($this->aus->tableroVacaciones($b, $anio))->sum('saldo'), 1), 'proximo_feriado' => $this->fer->proximos($b, 1)[0] ?? null],
        ]);
    }

    public function guardarAusencia(Request $request, ?int $id = null)
    {
        $d = $request->validate(['empleado_id' => 'required|integer', 'tipo' => 'required|string|max:30', 'desde' => 'required|date', 'hasta' => 'nullable|date', 'dias' => 'nullable|numeric|min:0', 'estado' => 'nullable|in:pendiente,aprobada,rechazada', 'anio_vacaciones' => 'nullable|integer', 'certificado' => 'boolean', 'notas' => 'nullable|string|max:1000']);
        $a = $this->aus->guardar($request->user()->business, $request->user(), $d, $id ? Ausencia::findOrFail($id) : null);
        return back()->with('success', "{$a->tipoLabel()} de {$a->empleado->nombre}: {$a->dias} días, del {$a->desde->format('d/m')} al {$a->hasta->format('d/m')}.");
    }

    public function decidir(Request $request, int $id)
    {
        $d = $request->validate(['estado' => 'required|in:aprobada,rechazada']);
        $a = $this->aus->decidir(Ausencia::findOrFail($id), $request->user(), $d['estado']);
        return back()->with('success', ($d['estado'] === 'aprobada' ? 'Aprobada: ' : 'Rechazada: ') . "{$a->tipoLabel()} de {$a->empleado->nombre}.");
    }

    public function borrarAusencia(int $id)
    {
        $a = Ausencia::findOrFail($id); $a->delete();
        return back()->with('success', 'Ausencia borrada.');
    }

    public function guardarFeriado(Request $request, ?int $id = null)
    {
        $d = $request->validate(['fecha' => 'required|date', 'nombre' => 'required|string|max:120', 'tipo' => 'required|in:propio,puente,no_laborable,quitado']);
        $this->fer->guardar($request->user()->business, $d, $id ? Feriado::findOrFail($id) : null);
        return back()->with('success', $d['tipo'] === 'quitado' ? 'Listo: ese feriado nacional se trabaja en esta empresa.' : 'Feriado guardado.');
    }

    public function borrarFeriado(int $id)
    {
        Feriado::findOrFail($id)->delete();
        return back()->with('success', 'Feriado quitado del calendario.');
    }
}
