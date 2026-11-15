<?php

namespace App\Services\Personal;

use App\Models\Business;
use App\Models\Feriado;
use App\Support\FeriadosArgentina;
use Carbon\Carbon;

// Fase 98: el calendario laboral de la empresa: feriados nacionales calculados + los propios (y los nacionales que esta empresa trabaja).
class FeriadosService
{
    // [fecha => ['nombre','tipo','origen' => nacional|propio]] del año, ya sin los "quitados".
    public function delAnio(Business $b, int $anio): array
    {
        $f = [];
        foreach (FeriadosArgentina::anio($anio) as $fecha => $x) $f[$fecha] = $x + ['origen' => 'nacional', 'id' => null];
        foreach (Feriado::withoutGlobalScopes()->where('business_id', $b->id)->whereYear('fecha', $anio)->orderBy('fecha')->get() as $p) {
            $k = $p->fecha->toDateString();
            if ($p->tipo === 'quitado') { unset($f[$k]); continue; }
            $f[$k] = ['nombre' => $p->nombre, 'tipo' => $p->tipo, 'origen' => 'propio', 'id' => $p->id];
        }
        ksort($f);
        return $f;
    }

    // Lo que se muestra en la pantalla: todos los del año, marcando también los quitados.
    public function listado(Business $b, int $anio): array
    {
        $quitados = Feriado::withoutGlobalScopes()->where('business_id', $b->id)->whereYear('fecha', $anio)->where('tipo', 'quitado')->get()->keyBy(fn($p) => $p->fecha->toDateString());
        $todos = $this->delAnio($b, $anio);
        foreach (FeriadosArgentina::anio($anio) as $fecha => $x) if (isset($quitados[$fecha])) $todos[$fecha] = $x + ['origen' => 'nacional', 'id' => $quitados[$fecha]->id, 'quitado' => true];
        ksort($todos);
        return collect($todos)->map(fn($x, $fecha) => ['fecha' => $fecha, 'fecha_label' => ucfirst(Carbon::parse($fecha)->locale('es')->isoFormat('ddd D [de] MMMM')), 'nombre' => $x['nombre'], 'tipo' => $x['tipo'], 'origen' => $x['origen'], 'id' => $x['id'], 'quitado' => $x['quitado'] ?? false, 'dow' => Carbon::parse($fecha)->dayOfWeekIso])->values()->all();
    }

    public function entre(Business $b, Carbon $desde, Carbon $hasta): array
    {
        $out = [];
        for ($a = $desde->year; $a <= $hasta->year; $a++) foreach ($this->delAnio($b, $a) as $fecha => $x) if ($fecha >= $desde->toDateString() && $fecha <= $hasta->toDateString()) $out[$fecha] = $x;
        return $out;
    }

    public function esFeriado(Business $b, Carbon $dia): bool { return isset($this->delAnio($b, $dia->year)[$dia->toDateString()]); }

    // Cuántos feriados "no trabajados" tiene el mes para el recibo: los que no caen domingo (el domingo ya es descanso).
    public function enMes(Business $b, string $periodo): int
    {
        $d = Carbon::parse($periodo . '-01');
        return count(array_filter($this->entre($b, $d, $d->copy()->endOfMonth()), fn($x, $fecha) => Carbon::parse($fecha)->dayOfWeekIso !== 7, ARRAY_FILTER_USE_BOTH));
    }

    public function proximos(Business $b, int $cuantos = 5): array
    {
        $hoy = today(); $out = [];
        foreach ($this->entre($b, $hoy, $hoy->copy()->addMonths(6)) as $fecha => $x) { $out[] = ['fecha' => $fecha, 'nombre' => $x['nombre'], 'en_dias' => (int) $hoy->diffInDays(Carbon::parse($fecha)), 'dia' => Carbon::parse($fecha)->locale('es')->isoFormat('dddd D [de] MMMM')]; if (count($out) >= $cuantos) break; }
        return $out;
    }

    public function guardar(Business $b, array $d, ?Feriado $f = null): Feriado
    {
        $f ??= new Feriado(['business_id' => $b->id]);
        $f->fill(['fecha' => $d['fecha'], 'nombre' => $d['nombre'], 'tipo' => $d['tipo'] ?? 'propio'])->save();
        return $f;
    }
}
