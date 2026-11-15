<?php

namespace App\Support;

use Carbon\Carbon;

// Fase 98: los feriados nacionales de la Argentina, calculados para cualquier año (ley 27.399).
// Inamovibles: fijos. Trasladables: si caen martes o miércoles pasan al lunes anterior; jueves o viernes, al lunes siguiente; sábado y domingo quedan.
// Los puentes turísticos se decretan cada año: se cargan como feriado propio tipo "puente".
class FeriadosArgentina
{
    // [fecha 'Y-m-d' => ['nombre' => ..., 'tipo' => 'inamovible'|'trasladable']]
    public static function anio(int $anio): array
    {
        $pascua = self::pascua($anio);
        $f = [];
        $pon = function (Carbon $d, string $nombre, string $tipo = 'inamovible') use (&$f) {
            if ($tipo === 'trasladable') {
                $dow = $d->dayOfWeekIso; // 1 lunes … 7 domingo
                if (in_array($dow, [2, 3], true)) $d = $d->copy()->subDays($dow - 1);
                elseif (in_array($dow, [4, 5], true)) $d = $d->copy()->addDays(8 - $dow);
            }
            $f[$d->toDateString()] = ['nombre' => $nombre, 'tipo' => $tipo];
        };
        $pon(Carbon::create($anio, 1, 1), 'Año Nuevo');
        $pon($pascua->copy()->subDays(48), 'Carnaval');
        $pon($pascua->copy()->subDays(47), 'Carnaval');
        $pon(Carbon::create($anio, 3, 24), 'Día de la Memoria por la Verdad y la Justicia');
        $pon(Carbon::create($anio, 4, 2), 'Día del Veterano y de los Caídos en Malvinas');
        $pon($pascua->copy()->subDays(2), 'Viernes Santo');
        $pon(Carbon::create($anio, 5, 1), 'Día del Trabajador');
        $pon(Carbon::create($anio, 5, 25), 'Revolución de Mayo');
        $pon(Carbon::create($anio, 6, 17), 'Paso a la Inmortalidad de Güemes', 'trasladable');
        $pon(Carbon::create($anio, 6, 20), 'Paso a la Inmortalidad de Belgrano');
        $pon(Carbon::create($anio, 7, 9), 'Día de la Independencia');
        $pon(Carbon::create($anio, 8, 17), 'Paso a la Inmortalidad de San Martín', 'trasladable');
        $pon(Carbon::create($anio, 10, 12), 'Día del Respeto a la Diversidad Cultural', 'trasladable');
        $pon(Carbon::create($anio, 11, 20), 'Día de la Soberanía Nacional', 'trasladable');
        $pon(Carbon::create($anio, 12, 8), 'Inmaculada Concepción de María');
        $pon(Carbon::create($anio, 12, 25), 'Navidad');
        ksort($f);
        return $f;
    }

    // Domingo de Pascua (algoritmo de Meeus/Jones/Butcher).
    public static function pascua(int $y): Carbon
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return Carbon::create($y, $mes, $dia);
    }
}
