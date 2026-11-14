<?php

namespace App\Services\Contabilidad;

use App\Models\Business;
use App\Models\CentroCosto;
use App\Models\Comprobante;
use App\Models\MovimientoFondos;
use App\Models\PresupuestoCentro;
use Illuminate\Support\Facades\DB;

// Fase 96: centros de costo con presupuesto anual contra real. El real sale de lo que la empresa ya carga: los gastos de Plata
// (egresos manuales con centro) y las compras (comprobantes de compra con centro). Lo que no tiene centro queda en "Sin centro".
class CentrosCostoService
{
    public const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    // Real por centro y mes: [centro_id|0 => [mes => monto]]
    public function real(Business $b, int $anio): array
    {
        $r = [];
        $compras = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'compra')->where('estado', '!=', 'anulado')->where('estado', '!=', 'borrador')
            ->whereNotIn('tipo', ['PRE', 'REM'])->whereYear('fecha', $anio)
            ->select('centro_costo_id', DB::raw(\App\Support\Sql::mes('fecha') . ' as mes'), DB::raw("SUM(CASE WHEN tipo IN ('NCA','NCB','NCC') THEN -total ELSE total END) as monto"))
            ->groupBy('centro_costo_id', DB::raw(\App\Support\Sql::mes('fecha')))->get();
        foreach ($compras as $x) { $m = (int) substr((string) $x->mes, -2); $r[(int) $x->centro_costo_id][$m] = ($r[(int) $x->centro_costo_id][$m] ?? 0) + (float) $x->monto; } // Sql::mes devuelve AAAA-MM
        $gastos = MovimientoFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('origen', 'gasto')->whereYear('fecha', $anio)
            ->select('centro_costo_id', DB::raw(\App\Support\Sql::mes('fecha') . ' as mes'), DB::raw('SUM(egreso) as monto'))
            ->groupBy('centro_costo_id', DB::raw(\App\Support\Sql::mes('fecha')))->get();
        foreach ($gastos as $x) { $m = (int) substr((string) $x->mes, -2); $r[(int) $x->centro_costo_id][$m] = ($r[(int) $x->centro_costo_id][$m] ?? 0) + (float) $x->monto; }
        return $r;
    }

    public function tablero(Business $b, int $anio): array
    {
        $real = $this->real($b, $anio);
        $pres = PresupuestoCentro::withoutGlobalScopes()->where('business_id', $b->id)->where('anio', $anio)->get()->groupBy(fn($p) => (int) $p->centro_costo_id)->map(fn($g) => $g->mapWithKeys(fn($p) => [(int) $p->mes => (float) $p->monto])->all())->all();
        $mesActual = (int) now()->format('n'); $esteAnio = (int) now()->format('Y') === $anio;
        $fila = function (?CentroCosto $c) use ($real, $pres, $mesActual, $esteAnio) {
            $id = $c?->id ?? 0; $meses = [];
            for ($m = 1; $m <= 12; $m++) {
                $p = (float) ($pres[$id][$m] ?? 0); $r = round((float) ($real[$id][$m] ?? 0), 2);
                $meses[] = ['mes' => $m, 'label' => self::MESES[$m - 1], 'presupuesto' => $p, 'real' => $r, 'desvio' => $p > 0 ? round(($r - $p) / $p * 100, 1) : null, 'pasado' => ! $esteAnio || $m < $mesActual, 'actual' => $esteAnio && $m === $mesActual];
            }
            $tp = array_sum(array_column($meses, 'presupuesto')); $tr = array_sum(array_column($meses, 'real'));
            // Lo que va del año: sólo los meses ya transcurridos (y el actual), para que el desvío tenga sentido.
            $hasta = $esteAnio ? $mesActual : 12; $pp = array_sum(array_map(fn($x) => $x['presupuesto'], array_slice($meses, 0, $hasta))); $rr = array_sum(array_map(fn($x) => $x['real'], array_slice($meses, 0, $hasta)));
            return ['id' => $id ?: null, 'nombre' => $c?->nombre ?? 'Sin centro', 'codigo' => $c?->codigo, 'activo' => $c?->activo ?? true, 'meses' => $meses, 'total_presupuesto' => round($tp, 2), 'total_real' => round($tr, 2),
                'a_la_fecha' => ['presupuesto' => round($pp, 2), 'real' => round($rr, 2), 'desvio' => $pp > 0 ? round(($rr - $pp) / $pp * 100, 1) : null]];
        };
        $centros = CentroCosto::withoutGlobalScopes()->where('business_id', $b->id)->orderBy('nombre')->get();
        $filas = $centros->map($fila)->values()->all();
        $sin = $fila(null); if ($sin['total_real'] > 0 || $sin['total_presupuesto'] > 0) $filas[] = $sin;
        $tp = array_sum(array_column($filas, 'total_presupuesto')); $tr = array_sum(array_column($filas, 'total_real'));
        // El desvío general compara sólo lo que tiene presupuesto: lo gastado sin presupuesto (o sin centro) se informa aparte, no infla el desvío.
        $conPres = array_filter($filas, fn($f) => $f['a_la_fecha']['presupuesto'] > 0);
        $pp = array_sum(array_map(fn($f) => $f['a_la_fecha']['presupuesto'], $conPres)); $rr = array_sum(array_map(fn($f) => $f['a_la_fecha']['real'], $conPres));
        $sinPres = round(array_sum(array_map(fn($f) => $f['a_la_fecha']['real'], array_filter($filas, fn($f) => $f['a_la_fecha']['presupuesto'] <= 0))), 2);
        $pasados = array_filter($filas, fn($f) => $f['a_la_fecha']['desvio'] !== null && $f['a_la_fecha']['desvio'] > 10);
        $fmt = fn($n) => '$ ' . number_format($n, 0, ',', '.');
        return ['anio' => $anio, 'meses' => self::MESES, 'filas' => $filas, 'totales' => ['presupuesto' => round($tp, 2), 'real' => round($tr, 2), 'a_la_fecha' => ['presupuesto' => round($pp, 2), 'real' => round($rr, 2), 'desvio' => $pp > 0 ? round(($rr - $pp) / $pp * 100, 1) : null, 'sin_presupuesto' => $sinPres]],
            'pasados' => array_values(array_map(fn($f) => ['nombre' => $f['nombre'], 'desvio' => $f['a_la_fecha']['desvio'], 'real' => $f['a_la_fecha']['real'], 'presupuesto' => $f['a_la_fecha']['presupuesto']], $pasados)),
            'resumen' => (! $pp ? 'Todavía no hay presupuesto cargado para ' . $anio . '.' : ($pasados ? count($pasados) . ' centro(s) por encima del presupuesto a la fecha: ' . implode(', ', array_map(fn($f) => "{$f['nombre']} (+{$f['a_la_fecha']['desvio']} %)", $pasados)) . '.' : 'Todos los centros con presupuesto están dentro de lo previsto a la fecha.')) . ($sinPres > 0 ? " Además hay {$fmt($sinPres)} gastados sin presupuesto o sin centro." : '')];
    }

    public function guardarPresupuesto(Business $b, ?int $centroId, int $anio, array $montos): void
    {
        foreach ($montos as $mes => $monto) {
            $mes = (int) $mes; if ($mes < 1 || $mes > 12) continue;
            PresupuestoCentro::withoutGlobalScopes()->updateOrCreate(['business_id' => $b->id, 'centro_costo_id' => $centroId ?: null, 'anio' => $anio, 'mes' => $mes], ['monto' => round((float) $monto, 2)]);
        }
    }
}
