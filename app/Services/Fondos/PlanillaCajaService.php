<?php

namespace App\Services\Fondos;

use App\Models\Cobro;
use App\Models\CuentaFondos;
use App\Models\MovimientoFondos;
use App\Models\Pago;
use App\Models\TurnoCaja;
use App\Services\Stock\ImportacionPreciosService;
use Illuminate\Validation\ValidationException;

// Fase 35.5: la planilla de caja que llevan en Excel, controlada contra lo cargado día por día: ingresos y egresos, recibos y pagos
// (por número), la rendición de cada cajera y los arqueos. No graba nada: dice qué cierra y qué no.
class PlanillaCajaService
{
    private const ALIAS = [
        'fecha' => ['fecha', 'dia', 'día', 'fecha mov'],
        'concepto' => ['concepto', 'detalle', 'descripcion', 'descripción', 'movimiento', 'tipo'],
        'ingreso' => ['ingreso', 'ingresos', 'entrada', 'entradas', 'debe', 'cobro', 'cobros'],
        'egreso' => ['egreso', 'egresos', 'salida', 'salidas', 'haber', 'pago', 'pagos', 'gasto', 'gastos'],
        'importe' => ['importe', 'monto', 'total'],
        'numero' => ['recibo', 'nro recibo', 'n recibo', 'numero', 'número', 'comprobante', 'nro', 'n°'],
        'cajera' => ['cajera', 'cajero', 'usuario', 'responsable', 'turno'],
        'arqueo' => ['arqueo', 'contado', 'efectivo contado', 'cierre'],
    ];

    public function __construct(private ImportacionPreciosService $planillas) {}

    public function controlar(CuentaFondos $caja, string $path, string $nombre): array
    {
        $tabla = $this->planillas->leer($path, $nombre, 20000);
        $h = null; $col = [];
        for ($i = 0; $i < min(15, count($tabla)); $i++) {
            $cols = array_map(fn($c) => mb_strtolower(trim((string) $c)), $tabla[$i]); $m = [];
            foreach (self::ALIAS as $k => $as) foreach ($cols as $j => $c) if (in_array($c, $as, true) && ! in_array($j, $m, true)) { $m[$k] = $j; break; }
            if (isset($m['fecha']) && (isset($m['importe']) || isset($m['ingreso']) || isset($m['egreso']))) { $h = $i; $col = $m; break; }
        }
        if ($h === null) throw ValidationException::withMessages(['archivo' => 'No encontré las columnas de fecha e importe (o ingreso/egreso) en la planilla.']);
        $lector = app(LiquidacionLectorService::class);
        $g = fn($f, $k) => isset($col[$k]) ? trim((string) ($f[$col[$k]] ?? '')) : '';
        $filas = [];
        foreach (array_slice($tabla, $h + 1) as $n => $f) {
            $fecha = $lector->fecha($g($f, 'fecha')); if (! $fecha) continue;
            $conc = $g($f, 'concepto'); $esArqueo = preg_match('/arqueo|cierre de caja|contado/i', $conc) || (isset($col['arqueo']) && $g($f, 'arqueo') !== '');
            $ing = $lector->num($g($f, 'ingreso')) ?? 0; $egr = $lector->num($g($f, 'egreso')) ?? 0;
            if (isset($col['importe']) && ! isset($col['ingreso']) && ! isset($col['egreso'])) { $v = $lector->num($g($f, 'importe')) ?? 0; $ing = $v > 0 ? $v : 0; $egr = $v < 0 ? -$v : 0; }
            $filas[] = ['renglon' => $h + $n + 2, 'fecha' => $fecha, 'concepto' => $conc, 'ingreso' => round(abs($ing), 2), 'egreso' => round(abs($egr), 2), 'numero' => preg_replace('/\D/', '', $g($f, 'numero')) ?: null, 'cajera' => $g($f, 'cajera') ?: null, 'arqueo' => $esArqueo ? ($lector->num($g($f, 'arqueo')) ?? $lector->num($g($f, 'importe')) ?? $ing) : null];
        }
        if (! $filas) throw ValidationException::withMessages(['archivo' => 'La planilla no tiene renglones con fecha.']);
        $desde = min(array_column($filas, 'fecha')); $hasta = max(array_column($filas, 'fecha'));
        $movs = MovimientoFondos::where('cuenta_fondos_id', $caja->id)->whereBetween('fecha', [$desde, $hasta])->get();
        $cobros = Cobro::whereBetween('fecha', [$desde, $hasta])->where('estado', '!=', 'anulado')->get(['id', 'numero', 'fecha', 'total', 'user_id']);
        $pagos = Pago::whereBetween('fecha', [$desde, $hasta])->where('estado', '!=', 'anulado')->get(['id', 'numero', 'fecha', 'total']);
        $turnos = TurnoCaja::with('user:id,name')->where('cuenta_fondos_id', $caja->id)->whereBetween('apertura', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->get();
        $dias = [];
        foreach (collect($filas)->groupBy('fecha') as $d => $fs) {
            $mv = $movs->filter(fn($m) => $m->fecha->toDateString() === $d);
            $pi = round($fs->whereNull('arqueo')->sum('ingreso'), 2); $pe = round($fs->whereNull('arqueo')->sum('egreso'), 2);
            $si = round((float) $mv->sum('ingreso'), 2); $se = round((float) $mv->sum('egreso'), 2);
            $tDia = $turnos->filter(fn($t) => $t->apertura->toDateString() === $d);
            $arqP = $fs->whereNotNull('arqueo')->values();
            $arqueos = $arqP->map(function ($a) use ($tDia) {
                $t = $tDia->first(fn($t) => ! $a['cajera'] || str_contains(mb_strtolower($t->user?->name ?? ''), mb_strtolower($a['cajera'])));
                return ['cajera' => $a['cajera'] ?? $t?->user?->name, 'planilla' => (float) $a['arqueo'], 'sistema' => $t?->saldo_contado !== null ? (float) $t->saldo_contado : null, 'diferencia' => $t?->saldo_contado !== null ? round((float) $a['arqueo'] - (float) $t->saldo_contado, 2) : null];
            })->all();
            $dias[] = ['fecha' => $d, 'planilla_ingresos' => $pi, 'planilla_egresos' => $pe, 'sistema_ingresos' => $si, 'sistema_egresos' => $se, 'dif_ingresos' => round($pi - $si, 2), 'dif_egresos' => round($pe - $se, 2), 'cierra' => abs($pi - $si) < 0.01 && abs($pe - $se) < 0.01 && collect($arqueos)->every(fn($a) => $a['diferencia'] === null || abs($a['diferencia']) < 0.01), 'arqueos' => $arqueos,
                'rendicion' => $tDia->map(fn($t) => ['cajera' => $t->user?->name, 'esperado' => (float) $t->saldo_esperado, 'contado' => $t->saldo_contado !== null ? (float) $t->saldo_contado : null, 'diferencia' => (float) $t->diferencia, 'planilla' => round($fs->filter(fn($f) => $f['cajera'] && str_contains(mb_strtolower($t->user?->name ?? ''), mb_strtolower($f['cajera'])))->whereNull('arqueo')->sum(fn($f) => $f['ingreso'] - $f['egreso']), 2)])->values()->all()];
        }
        // Recibos y pagos por número: en la planilla y no en el sistema, y al revés (sólo los de esta caja: los que movieron el fondo).
        $numsPlanilla = collect($filas)->pluck('numero')->filter()->map(fn($n) => (int) $n)->unique();
        $cobrosCaja = $cobros->filter(fn($c) => $movs->contains(fn($m) => $m->origen === 'cobro' && $m->origen_id === $c->id));
        $pagosCaja = $pagos->filter(fn($p) => $movs->contains(fn($m) => $m->origen === 'pago' && $m->origen_id === $p->id));
        $numsSistema = $cobrosCaja->pluck('numero')->merge($pagosCaja->pluck('numero'))->map(fn($n) => (int) $n);
        return ['caja' => $caja->nombre, 'desde' => $desde, 'hasta' => $hasta, 'renglones' => count($filas), 'dias' => $dias, 'dias_que_cierran' => collect($dias)->where('cierra', true)->count(),
            'recibos_solo_planilla' => $numsPlanilla->diff($numsSistema)->values()->all(),
            'recibos_solo_sistema' => $cobrosCaja->filter(fn($c) => ! $numsPlanilla->contains((int) $c->numero))->map(fn($c) => ['numero' => $c->numeroFormateado(), 'fecha' => $c->fecha->format('d/m'), 'total' => (float) $c->total])->values()->all(),
            'pagos_solo_sistema' => $pagosCaja->filter(fn($p) => ! $numsPlanilla->contains((int) $p->numero))->map(fn($p) => ['numero' => $p->numeroFormateado(), 'fecha' => $p->fecha->format('d/m'), 'total' => (float) $p->total])->values()->all(),
            'columnas' => array_keys($col)];
    }
}
