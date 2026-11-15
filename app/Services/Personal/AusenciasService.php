<?php

namespace App\Services\Personal;

use App\Models\AuditLog;
use App\Models\Ausencia;
use App\Models\Business;
use App\Models\Empleado;
use App\Models\User;
use Carbon\Carbon;

// Fase 98: ausencias, licencias y vacaciones. Las vacaciones se calculan como dice la LCT (art. 150): por antigüedad al 31/12.
class AusenciasService
{
    public function __construct(private FeriadosService $feriados) {}

    // Días de vacaciones que le corresponden por el año (LCT art. 150/151): menos de 6 meses → 1 día cada 20 trabajados; hasta 5 años → 14; hasta 10 → 21; hasta 20 → 28; más → 35.
    public function derechoVacaciones(Empleado $e, int $anio): array
    {
        $corte = Carbon::create($anio, 12, 31);
        $ingreso = $e->fecha_ingreso->copy();
        if ($ingreso->gt($corte)) return ['dias' => 0, 'regla' => 'Ingresa después del 31/12', 'antiguedad' => 0];
        $meses = (int) $ingreso->diffInMonths($corte);
        $anios = (int) $ingreso->diffInYears($corte);
        if ($meses < 6) {
            $trabajados = (int) round($ingreso->diffInDays($corte) * 6 / 7); // de lunes a sábado
            $dias = intdiv($trabajados, 20);
            $regla = "Menos de 6 meses: 1 día cada 20 trabajados ({$trabajados} días)";
        } elseif ($anios < 5) { $dias = 14; $regla = 'Hasta 5 años de antigüedad'; }
        elseif ($anios < 10) { $dias = 21; $regla = 'Entre 5 y 10 años'; }
        elseif ($anios < 20) { $dias = 28; $regla = 'Entre 10 y 20 años'; }
        else { $dias = 35; $regla = 'Más de 20 años'; }
        return ['dias' => $dias, 'regla' => $regla, 'antiguedad' => $anios];
    }

    public function tomadas(Empleado $e, int $anio): float
    {
        return (float) Ausencia::withoutGlobalScopes()->where('empleado_id', $e->id)->where('tipo', 'vacaciones')->where('estado', 'aprobada')->where('anio_vacaciones', $anio)->sum('dias');
    }

    public function saldoVacaciones(Empleado $e, int $anio): array
    {
        $d = $this->derechoVacaciones($e, $anio); $t = $this->tomadas($e, $anio); $ajuste = (float) ($e->vacaciones_ajuste ?? 0);
        return ['anio' => $anio, 'derecho' => $d['dias'], 'regla' => $d['regla'], 'ajuste' => $ajuste, 'tomadas' => $t, 'saldo' => round($d['dias'] + $ajuste - $t, 1)];
    }

    // Cuenta los días del tramo según el tipo: corridos, o hábiles (sin domingos ni feriados).
    public function contarDias(Business $b, string $tipo, Carbon $desde, Carbon $hasta): float
    {
        if ($hasta->lt($desde)) return 0;
        if ((Ausencia::TIPOS[$tipo]['conteo'] ?? 'corridos') === 'corridos') return (float) $desde->diffInDays($hasta) + 1;
        $fer = $this->feriados->entre($b, $desde, $hasta); $n = 0;
        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) if ($d->dayOfWeekIso !== 7 && ! isset($fer[$d->toDateString()])) $n++;
        return (float) $n;
    }

    public function guardar(Business $b, User $quien, array $d, ?Ausencia $a = null): Ausencia
    {
        $e = Empleado::withoutGlobalScopes()->where('business_id', $b->id)->findOrFail($d['empleado_id']);
        $desde = Carbon::parse($d['desde']); $hasta = Carbon::parse($d['hasta'] ?? $d['desde']);
        abort_if($hasta->lt($desde), 422, 'La fecha "hasta" es anterior a "desde".');
        $tipo = $d['tipo']; abort_unless(isset(Ausencia::TIPOS[$tipo]), 422, 'Tipo de ausencia desconocido.');
        $pisa = Ausencia::withoutGlobalScopes()->where('empleado_id', $e->id)->where('estado', '!=', 'rechazada')->when($a, fn($q) => $q->where('id', '!=', $a->id))->where('desde', '<=', $hasta->toDateString())->where('hasta', '>=', $desde->toDateString())->first();
        if ($pisa) abort(422, "Se pisa con {$pisa->tipoLabel()} del {$pisa->desde->format('d/m')} al {$pisa->hasta->format('d/m')}.");
        $dias = isset($d['dias']) && $d['dias'] !== null && $d['dias'] !== '' ? (float) $d['dias'] : $this->contarDias($b, $tipo, $desde, $hasta);
        $estado = $d['estado'] ?? 'aprobada';
        $a ??= new Ausencia(['business_id' => $b->id, 'creada_por' => $quien->id]);
        $a->fill(['empleado_id' => $e->id, 'tipo' => $tipo, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'dias' => $dias, 'estado' => $estado, 'certificado' => (bool) ($d['certificado'] ?? false), 'notas' => $d['notas'] ?? null,
            'anio_vacaciones' => $tipo === 'vacaciones' ? (int) ($d['anio_vacaciones'] ?? ($desde->month <= 4 ? $desde->year - 1 : $desde->year)) : null, // las vacaciones de enero-abril suelen ser del año anterior (LCT art. 154)
            'aprobada_por' => $estado === 'aprobada' ? $quien->id : null])->save();
        AuditLog::registrar($a->wasRecentlyCreated ? 'crear' : 'editar', $a, "{$a->tipoLabel()} de {$e->nombre}: {$desde->format('d/m/Y')} al {$hasta->format('d/m/Y')} ({$dias} días)");
        return $a;
    }

    public function decidir(Ausencia $a, User $quien, string $estado): Ausencia
    {
        $a->forceFill(['estado' => $estado, 'aprobada_por' => $estado === 'aprobada' ? $quien->id : null])->save();
        AuditLog::registrar('editar', $a, ucfirst($estado) . " {$a->tipoLabel()} de {$a->empleado->nombre}");
        return $a;
    }

    public function arr(Ausencia $a): array
    {
        return ['id' => $a->id, 'empleado_id' => $a->empleado_id, 'empleado' => $a->empleado?->nombre, 'legajo' => $a->empleado?->legajo, 'tipo' => $a->tipo, 'tipo_label' => $a->tipoLabel(), 'efecto' => Ausencia::efecto($a->tipo), 'desde' => $a->desde->toDateString(), 'hasta' => $a->hasta->toDateString(), 'desde_label' => $a->desde->format('d/m/Y'), 'hasta_label' => $a->hasta->format('d/m/Y'), 'dias' => (float) $a->dias, 'estado' => $a->estado, 'anio_vacaciones' => $a->anio_vacaciones, 'certificado' => $a->certificado, 'notas' => $a->notas];
    }

    // Las novedades del mes que salen solas de las ausencias aprobadas: vacaciones, faltas/sin goce/suspensiones (bajan días) y feriados del mes.
    // [empleado_id => ['vacaciones' => n, 'faltas' => n, 'feriados' => n, 'dias' => n, 'detalle' => [...]]]
    public function novedadesPeriodo(Business $b, string $periodo): array
    {
        $ini = Carbon::parse($periodo . '-01'); $fin = $ini->copy()->endOfMonth();
        $feriados = $this->feriados->enMes($b, $periodo);
        $out = [];
        foreach (Empleado::withoutGlobalScopes()->where('business_id', $b->id)->where('activo', true)->get(['id']) as $e) $out[$e->id] = ['vacaciones' => 0, 'faltas' => 0, 'feriados' => $feriados, 'dias' => max(0, 30 - $feriados), 'detalle' => []];
        $aus = Ausencia::withoutGlobalScopes()->with('empleado:id,nombre')->where('business_id', $b->id)->where('estado', 'aprobada')->where('desde', '<=', $fin->toDateString())->where('hasta', '>=', $ini->toDateString())->get();
        foreach ($aus as $a) {
            if (! isset($out[$a->empleado_id])) continue;
            $d1 = $a->desde->max($ini); $d2 = $a->hasta->min($fin);
            $efecto = Ausencia::efecto($a->tipo);
            $dias = $efecto === 'paga' ? 0 : $this->contarDias($b, $a->tipo, $d1, $d2);
            if ($efecto === 'vacaciones') $out[$a->empleado_id]['vacaciones'] += $dias;
            elseif ($efecto === 'descuenta') $out[$a->empleado_id]['faltas'] += $dias;
            $out[$a->empleado_id]['detalle'][] = $a->tipoLabel() . " {$d1->format('d/m')}–{$d2->format('d/m')}" . ($dias ? " ({$dias} d)" : '');
        }
        foreach ($out as $id => &$n) $n['dias'] = max(0, 30 - $n['feriados'] - (int) $n['vacaciones'] - (int) $n['faltas']);
        return $out;
    }

    public function hoy(Business $b, ?Carbon $dia = null): array
    {
        $dia ??= today();
        return Ausencia::withoutGlobalScopes()->with('empleado:id,nombre,legajo')->where('business_id', $b->id)->where('estado', 'aprobada')->where('desde', '<=', $dia->toDateString())->where('hasta', '>=', $dia->toDateString())->get()->map(fn($a) => $this->arr($a))->all();
    }

    public function proximasVacaciones(Business $b, int $dias = 30): array
    {
        return Ausencia::withoutGlobalScopes()->with('empleado:id,nombre,legajo')->where('business_id', $b->id)->where('estado', 'aprobada')->where('tipo', 'vacaciones')->where('desde', '>', today()->toDateString())->where('desde', '<=', today()->addDays($dias)->toDateString())->orderBy('desde')->get()->map(fn($a) => $this->arr($a))->all();
    }

    public function delMes(Business $b, string $mes): array
    {
        $ini = Carbon::parse($mes . '-01'); $fin = $ini->copy()->endOfMonth();
        return Ausencia::withoutGlobalScopes()->with('empleado:id,nombre,legajo')->where('business_id', $b->id)->where('desde', '<=', $fin->toDateString())->where('hasta', '>=', $ini->toDateString())->orderBy('desde')->get()->map(fn($a) => $this->arr($a))->all();
    }

    // Tablero de vacaciones de todos los activos para un año.
    public function tableroVacaciones(Business $b, int $anio): array
    {
        return Empleado::withoutGlobalScopes()->where('business_id', $b->id)->where('activo', true)->orderBy('legajo')->get()->map(fn($e) => ['id' => $e->id, 'legajo' => $e->legajo, 'nombre' => $e->nombre, 'ingreso' => $e->fecha_ingreso->format('d/m/Y')] + $this->saldoVacaciones($e, $anio))->all();
    }
}
