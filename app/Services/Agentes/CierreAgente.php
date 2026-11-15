<?php

namespace App\Services\Agentes;

use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Models\Propuesta;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Contabilidad\ContabilidadService;
use App\Services\Contabilidad\InformeContadorService;
use Illuminate\Support\Facades\DB;

// Fase 107 · Cierre del mes propuesto: los primeros días del mes (o cuando se lo pide) revisa el mes anterior antes de que entre el
// contador: comprobantes sin asiento (propone sincronizar), gastos sin categoría (propone la categoría por lo que se hizo antes con el
// mismo concepto), cajas con diferencia y lo demás que el informe del contador marca para revisar. El contador sólo acepta o corrige.
class CierreAgente implements Agente
{
    public const HASTA_DIA = 10;

    public function __construct(private InformeContadorService $informe, private ContabilidadService $contabilidad) {}

    public function nombre(): string { return 'cierre'; }

    public function proponer(Business $b, bool $forzar = false): array
    {
        if (! $forzar && today()->day > self::HASTA_DIA) return [];
        $ini = today()->subMonthNoOverflow()->startOfMonth(); $mes = $ini->format('Y-m'); $fin = $ini->copy()->endOfMonth();
        $d = $this->informe->datos($b, $mes); $out = []; $nombre = ucfirst($ini->locale('es')->isoFormat('MMMM'));

        // 1. Comprobantes sin asiento → sincronizar.
        $sinAsiento = DB::table('comprobantes')->where('business_id', $b->id)->where('estado', 'emitido')->where('sin_arca', false)->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('asientos')->whereColumn('asientos.origen_id', 'comprobantes.id')->whereColumn('asientos.origen', 'comprobantes.direccion'))->count();
        if ($sinAsiento) $out[] = ['clave' => "asientos-{$mes}", 'prioridad' => 1, 'titulo' => "{$nombre}: {$sinAsiento} comprobante(s) sin asiento contable", 'detalle' => 'Aprobar genera los asientos que faltan (lo mismo que Contable → Sincronizar). Sin esto el libro diario y el resultado del mes quedan cortos.',
            'datos' => ['mes' => $mes, 'cantidad' => $sinAsiento], 'accion' => ['tipo' => 'sincronizar']];

        // 2. Gastos sin categoría → la categoría más usada antes para el mismo concepto.
        $sinCat = MovimientoFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('origen', 'gasto')->whereNull('expense_category_id')->whereBetween('fecha', [$ini->toDateString(), $fin->toDateString()])->orderByDesc('egreso')->limit(80)->get();
        if ($sinCat->isNotEmpty()) {
            $cats = ExpenseCategory::withoutGlobalScopes()->where('business_id', $b->id)->pluck('name', 'id');
            $con = []; $sin = [];
            foreach ($sinCat as $m) {
                $raiz = $this->raiz((string) $m->concepto);
                $cat = $raiz === '' ? null : MovimientoFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('origen', 'gasto')->whereNotNull('expense_category_id')->where('concepto', \App\Support\Sql::like(), $raiz . '%')
                    ->selectRaw('expense_category_id, COUNT(*) n')->groupBy('expense_category_id')->orderByDesc('n')->value('expense_category_id');
                $fila = ['id' => $m->id, 'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->format('d/m') : (string) $m->fecha, 'concepto' => $m->concepto, 'importe' => (float) $m->egreso];
                if ($cat && isset($cats[$cat])) $con[] = $fila + ['categoria_id' => (int) $cat, 'categoria' => $cats[$cat]]; else $sin[] = $fila;
            }
            if ($con) $out[] = ['clave' => "categorias-{$mes}", 'prioridad' => 2, 'importe' => round(array_sum(array_column($con, 'importe')), 2), 'titulo' => "{$nombre}: " . count($con) . ' gasto(s) sin categoría, con la categoría propuesta',
                'detalle' => 'La misma que usaste antes para ese concepto. Aprobar las aplica; lo que no coincida, cambialo en Plata → Gastos.', 'datos' => ['items' => $con], 'accion' => ['tipo' => 'categorizar', 'items' => array_map(fn($x) => ['id' => $x['id'], 'categoria_id' => $x['categoria_id']], $con)]];
            if ($sin) $out[] = ['clave' => "sin-categoria-{$mes}", 'prioridad' => 3, 'importe' => round(array_sum(array_column($sin, 'importe')), 2), 'titulo' => "{$nombre}: " . count($sin) . ' gasto(s) sin categoría que no sé dónde van',
                'detalle' => 'Nunca cargaste un gasto parecido con categoría. Ponésela en Plata → Gastos y la próxima vez la propongo yo.', 'datos' => ['items' => $sin], 'accion' => null];
        }

        // 3. Cajas que cerraron con diferencia en el mes.
        $difs = TurnoCaja::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('cierre')->whereBetween('cierre', [$ini, $fin->copy()->endOfDay()])->where(fn($q) => $q->where('diferencia', '<=', -500)->orWhere('diferencia', '>=', 500))->with('user:id,name')->orderBy('cierre')->get();
        if ($difs->isNotEmpty()) $out[] = ['clave' => "cajas-{$mes}", 'prioridad' => 2, 'importe' => round((float) $difs->sum(fn($t) => abs((float) $t->diferencia)), 2), 'titulo' => "{$nombre}: {$difs->count()} cierre(s) de caja con diferencia",
            'detalle' => 'Faltantes ' . AgentesService::pesos(abs((float) $difs->where('diferencia', '<', 0)->sum('diferencia'))) . ' · sobrantes ' . AgentesService::pesos((float) $difs->where('diferencia', '>', 0)->sum('diferencia')) . '. El contador decide si van a resultado o a cuenta de quien cerró.',
            'datos' => ['items' => $difs->map(fn($t) => ['fecha' => $t->cierre->format('d/m'), 'usuario' => $t->user?->name ?? '—', 'diferencia' => (float) $t->diferencia])->values()->all()], 'accion' => null];

        // 4. Lo demás que el informe del contador marca para revisar (CAE pendiente, manuales, compras que faltan…).
        $resto = array_values(array_filter($d['revisar'] ?? [], fn($t) => ! str_contains($t, 'sin asiento') && ! str_contains($t, 'sin categoría')));
        if ($resto) $out[] = ['clave' => "revisar-{$mes}", 'prioridad' => 2, 'titulo' => "{$nombre}: " . count($resto) . ' cosa(s) para revisar antes de liquidar', 'detalle' => implode("\n", array_map(fn($t) => "• {$t}", $resto)), 'datos' => ['items' => $resto, 'mes' => $mes], 'accion' => null];
        return $out;
    }

    private function raiz(string $concepto): string
    {
        $c = mb_strtolower(trim(preg_replace('/[\d\/\-\.,#:]+/', ' ', $concepto)));
        return trim(implode(' ', array_slice(preg_split('/\s+/', $c), 0, 2)));
    }

    public function ejecutar(Propuesta $p, User $u): string
    {
        return match ($p->accion['tipo']) {
            'sincronizar' => $this->contabilidad->sincronizar($p->business_id) . ' asiento(s) generados.',
            'categorizar' => (function () use ($p) { $n = 0; foreach ($p->accion['items'] as $i) $n += MovimientoFondos::withoutGlobalScopes()->where('business_id', $p->business_id)->where('id', $i['id'])->whereNull('expense_category_id')->update(['expense_category_id' => $i['categoria_id']]); return "{$n} gasto(s) categorizados."; })(),
            default => 'Nada que hacer.',
        };
    }
}
