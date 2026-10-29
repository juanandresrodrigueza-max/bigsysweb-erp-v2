<?php

namespace App\Services\Estadisticas;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Product;
use App\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Fase 33: el tablero gerencial. Cada número dice cómo se calcula y contra qué se compara: el período anterior del mismo largo y el mismo
// período del año pasado. Lo facturado da igual que el Libro de IVA: facturas menos notas de crédito, sin anuladas; los remitos no cuentan.
class TableroService
{
    public const FACTURAS = ['FA', 'FB', 'FC', 'FE'];
    public const CON_NC = ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC', 'NCA', 'NCB', 'NCC'];
    public const SIGNO = "CASE WHEN comprobantes.tipo IN ('NCA','NCB','NCC') THEN -1 ELSE 1 END";

    public const COMO = [
        'ventas' => 'Con IVA: facturas más notas de débito menos notas de crédito emitidas en el período, sin anuladas. Da igual que el Libro de IVA ventas.',
        'comprobantes' => 'Cantidad de facturas emitidas en el período (las notas de crédito no cuentan).',
        'ticket' => 'Ventas con IVA dividido la cantidad de facturas del período.',
        'margen' => 'Sin IVA: ventas netas menos el costo que tenía cada artículo el día que se vendió. El % es sobre la venta neta.',
        'clientes' => 'Clientes distintos con al menos una factura en el período.',
        'hoy' => 'Lo facturado hoy hasta esta hora (con IVA, sin las notas de crédito), contra el mismo día de la semana pasada hasta la misma hora y contra el promedio de los últimos 4 iguales.',
        'proyeccion' => 'Lo facturado del mes hasta ayer, más lo que falta estimado con el promedio de cada día de la semana en las últimas 8 semanas. Es una cuenta, no una promesa.',
        'abc' => 'Los artículos del período ordenados por lo facturado sin IVA: A suma el primer 80 %, B hasta el 95 %, C el resto.',
        'calor' => 'Lo facturado con IVA por día de la semana y hora del comprobante en el período.',
        'puntos' => 'Lo facturado con IVA por punto de venta (la serie del comprobante) en el período y en el mismo período un año atrás.',
        'frenados' => 'Artículos con stock que se vendían al menos 3 de las 6 semanas anteriores y no se vendieron nada en los últimos 14 días.',
        'vendedores' => 'Por vendedor (el de la factura; si no tiene, el asignado al cliente): ventas con IVA, variación contra el período anterior del mismo largo, parte del total, facturas, ticket, clientes distintos, notas de crédito y la hora en que más factura.',
    ];

    private function ventas(string $d, string $h, ?int $sucursal = null)
    {
        return Comprobante::ventas()->emitidos()->whereIn('tipo', self::CON_NC)->whereBetween('fecha', [$d, $h])->when($sucursal, fn($q) => $q->where('business_location_id', $sucursal));
    }

    private function suma($q): float { return (float) $q->selectRaw('COALESCE(SUM((' . self::SIGNO . ') * comprobantes.total),0) s')->value('s'); }

    public static function pct(float $a, float $b): ?float { return $b > 0 ? round(($a - $b) / $b * 100, 1) : null; }

    // Un número con sus dos comparaciones: contra el período anterior del mismo largo y contra el mismo período del año pasado.
    public function comparar(callable $f, Carbon $desde, Carbon $hasta): array
    {
        $dias = $desde->diffInDays($hasta) + 1;
        $v = (float) $f($desde->toDateString(), $hasta->toDateString());
        $prev = (float) $f($desde->copy()->subDays($dias)->toDateString(), $hasta->copy()->subDays($dias)->toDateString());
        $anio = (float) $f($desde->copy()->subYear()->toDateString(), $hasta->copy()->subYear()->toDateString());
        return ['valor' => $v, 'anterior' => $prev, 'anio' => $anio, 'var' => self::pct($v, $prev), 'var_anio' => self::pct($v, $anio)];
    }

    // Hoy, hasta esta hora: contra el mismo día de la semana pasada a la misma hora y contra el promedio de los últimos 4 iguales.
    public function hoy(?int $sucursal = null, ?Carbon $ahora = null): array
    {
        $ahora ??= now(); $hoy = $ahora->toDateString();
        $hasta = fn(Carbon $dia) => $this->ventas($dia->toDateString(), $dia->toDateString(), $sucursal)->where(fn($q) => $q->whereNull('emitido_en')->orWhere('emitido_en', '<=', $dia->copy()->setTime($ahora->hour, $ahora->minute, 59)));
        $v = $this->suma($hasta($ahora)); $sem = $this->suma($hasta($ahora->copy()->subWeek()));
        $prom = collect(range(1, 4))->map(fn($i) => $this->suma($hasta($ahora->copy()->subWeeks($i))))->avg();
        $ultimo = $hasta($ahora)->whereIn('tipo', self::FACTURAS)->max('emitido_en');
        return ['ventas' => $v, 'semana_pasada' => $sem, 'var_semana' => self::pct($v, $sem), 'promedio4' => round((float) $prom, 2), 'var_promedio' => self::pct($v, (float) $prom), 'hora' => $ahora->format('H:i'), 'dia' => $ahora->locale('es')->isoFormat('dddd'),
            'ultimo' => $ultimo ? Carbon::parse($ultimo)->format('H:i') : null, 'facturas' => $this->ventas($hoy, $hoy, $sucursal)->whereIn('tipo', self::FACTURAS)->count(), 'como' => self::COMO['hoy']];
    }

    // Cómo va el mes: lo facturado hasta ayer y la proyección de cierre según lo que se vende cada día de la semana.
    public function proyeccionMes(?int $sucursal = null, ?Carbon $hoy = null): array
    {
        $hoy ??= today(); $ini = $hoy->copy()->startOfMonth(); $fin = $hoy->copy()->endOfMonth();
        $hastaAyer = $hoy->day > 1 ? $this->suma($this->ventas($ini->toDateString(), $hoy->copy()->subDay()->toDateString(), $sucursal)) : 0.0;
        $deHoy = $this->suma($this->ventas($hoy->toDateString(), $hoy->toDateString(), $sucursal));
        // promedio por día de la semana en las últimas 8 semanas (antes de hoy)
        $d8 = $hoy->copy()->subWeeks(8)->toDateString(); $ayer = $hoy->copy()->subDay()->toDateString();
        $porDia = $this->ventas($d8, $ayer, $sucursal)->selectRaw(Sql::diaSemana('comprobantes.fecha') . ' as d, SUM((' . self::SIGNO . ') * comprobantes.total) as monto')->groupBy('d')->pluck('monto', 'd')->map(fn($m) => (float) $m / 8);
        $resto = 0.0; $faltan = 0;
        for ($dia = $hoy->copy(); $dia->lte($fin); $dia->addDay()) { $resto += (float) ($porDia[$dia->dayOfWeek] ?? 0); $faltan++; }
        $datos = $this->ventas($d8, $ayer, $sucursal)->whereIn('tipo', self::FACTURAS)->count() >= 20;
        $mesPasado = $this->suma($this->ventas($ini->copy()->subMonth()->toDateString(), $ini->copy()->subMonth()->endOfMonth()->toDateString(), $sucursal));
        $anioPasado = $this->suma($this->ventas($ini->copy()->subYear()->toDateString(), $fin->copy()->subYear()->toDateString(), $sucursal));
        $proy = $datos ? round($hastaAyer + max($deHoy, (float) ($porDia[$hoy->dayOfWeek] ?? 0)) + $resto - (float) ($porDia[$hoy->dayOfWeek] ?? 0), 2) : null;
        return ['hasta_ayer' => $hastaAyer, 'hoy' => $deHoy, 'proyeccion' => $proy, 'faltan_dias' => $faltan, 'mes_pasado' => $mesPasado, 'anio_pasado' => $anioPasado, 'var_mes_pasado' => $proy !== null ? self::pct($proy, $mesPasado) : null, 'var_anio_pasado' => $proy !== null ? self::pct($proy, $anioPasado) : null,
            'mes' => $hoy->locale('es')->isoFormat('MMMM YYYY'), 'suficiente' => $datos, 'como' => self::COMO['proyeccion'] . ($datos ? '' : ' Todavía no se puede saber: hacen falta al menos 20 facturas en las últimas 8 semanas.')];
    }

    private function items(string $d, string $h, ?int $sucursal = null)
    {
        return ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->where('comprobantes.business_id', auth()->user()->business_id)
            ->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', ['FA', 'FB', 'FC', 'FE', 'NCA', 'NCB', 'NCC'])
            ->whereBetween('comprobantes.fecha', [$d, $h])->when($sucursal, fn($q) => $q->where('comprobantes.business_location_id', $sucursal))->whereNotNull('comprobante_items.product_id');
    }

    // Matriz ABC del período: A el primer 80 % de lo facturado sin IVA, B hasta el 95 %, C el resto.
    public function abc(Carbon $desde, Carbon $hasta, ?int $sucursal = null, int $limite = 300): array
    {
        $filas = $this->items($desde->toDateString(), $hasta->toDateString(), $sucursal)->join('products', 'products.id', '=', 'comprobante_items.product_id')
            ->selectRaw('products.id, products.name as nombre, products.sku, products.stock, SUM((' . self::SIGNO . ') * (comprobante_items.neto - comprobante_items.descuento)) as neto, SUM((' . self::SIGNO . ') * comprobante_items.cantidad) as unidades')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.stock')->orderByDesc('neto')->get();
        $total = (float) $filas->sum('neto'); $acum = 0; $out = []; $cnt = ['A' => 0, 'B' => 0, 'C' => 0]; $monto = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        foreach ($filas as $f) {
            $acum += (float) $f->neto; $share = $total > 0 ? $acum / $total : 1;
            $clase = $share <= 0.8 ? 'A' : ($share <= 0.95 ? 'B' : 'C'); $cnt[$clase]++; $monto[$clase] += (float) $f->neto;
            if (count($out) < $limite) $out[] = ['id' => $f->id, 'nombre' => $f->nombre, 'sku' => $f->sku, 'neto' => round((float) $f->neto, 2), 'unidades' => (float) $f->unidades, 'pct' => $total > 0 ? round((float) $f->neto / $total * 100, 1) : 0, 'acum' => round($share * 100, 1), 'clase' => $clase, 'stock' => (float) $f->stock];
        }
        return ['items' => $out, 'total' => round($total, 2), 'cantidad' => $cnt, 'monto' => array_map(fn($m) => round($m, 2), $monto), 'como' => self::COMO['abc']];
    }

    // Mapa de calor día por hora, y el mejor y peor día del período.
    public function cuandoVende(Carbon $desde, Carbon $hasta, ?int $sucursal = null): array
    {
        $q = $this->ventas($desde->toDateString(), $hasta->toDateString(), $sucursal)->whereNotNull('emitido_en');
        $celdas = (clone $q)->selectRaw(Sql::diaSemana('comprobantes.emitido_en') . ' as d, ' . Sql::hora('comprobantes.emitido_en') . ' as h, SUM((' . self::SIGNO . ') * comprobantes.total) as monto, COUNT(*) as n')->groupBy('d', 'h')->get()
            ->map(fn($r) => ['d' => (int) $r->d, 'h' => (int) $r->h, 'monto' => (float) $r->monto, 'n' => (int) $r->n])->values()->all();
        $porDia = $this->ventas($desde->toDateString(), $hasta->toDateString(), $sucursal)->selectRaw('comprobantes.fecha as f, SUM((' . self::SIGNO . ') * comprobantes.total) as monto, SUM(CASE WHEN comprobantes.tipo IN (\'FA\',\'FB\',\'FC\',\'FE\') THEN 1 ELSE 0 END) as n')->groupBy('f')->orderBy('f')->get()->map(fn($r) => ['fecha' => (string) $r->f, 'monto' => (float) $r->monto, 'n' => (int) $r->n]);
        $conVentas = $porDia->filter(fn($d) => $d['monto'] > 0);
        $horas = collect($celdas)->groupBy('h')->map(fn($g) => $g->sum('monto'));
        $diasSem = collect($celdas)->groupBy('d')->map(fn($g) => $g->sum('monto'));
        $nombres = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        return ['celdas' => $celdas, 'mejor_dia' => $conVentas->sortByDesc('monto')->first(), 'peor_dia' => $conVentas->sortBy('monto')->first(),
            'mejor_hora' => $horas->isNotEmpty() ? ['hora' => $horas->sortDesc()->keys()->first(), 'monto' => $horas->max()] : null, 'peor_hora' => $horas->isNotEmpty() ? ['hora' => $horas->sort()->keys()->first(), 'monto' => $horas->min()] : null,
            'mejor_dia_semana' => $diasSem->isNotEmpty() ? $nombres[$diasSem->sortDesc()->keys()->first()] : null, 'dias' => $porDia->values()->all(), 'como' => self::COMO['calor']];
    }

    // Ventas por punto de venta: este período contra el mismo del año anterior.
    public function porPuntoVenta(Carbon $desde, Carbon $hasta, ?int $sucursal = null): array
    {
        $g = fn(string $d, string $h) => $this->ventas($d, $h, $sucursal)->leftJoin('puntos_venta', 'puntos_venta.id', '=', 'comprobantes.punto_venta_id')
            ->selectRaw('comprobantes.punto_venta_id as pv, puntos_venta.numero as numero, SUM((' . self::SIGNO . ') * comprobantes.total) as monto, SUM(CASE WHEN comprobantes.tipo IN (\'FA\',\'FB\',\'FC\',\'FE\') THEN 1 ELSE 0 END) as n')->groupBy('comprobantes.punto_venta_id', 'puntos_venta.numero')->get()->keyBy('pv');
        $act = $g($desde->toDateString(), $hasta->toDateString()); $ant = $g($desde->copy()->subYear()->toDateString(), $hasta->copy()->subYear()->toDateString());
        $out = [];
        foreach ($act->keys()->merge($ant->keys())->unique() as $pv) {
            $a = $act[$pv] ?? null; $b = $ant[$pv] ?? null;
            $out[] = ['punto_venta_id' => $pv, 'numero' => $a?->numero ?? $b?->numero, 'nombre' => $pv ? 'Punto de venta ' . str_pad((string) ($a?->numero ?? $b?->numero), 4, '0', STR_PAD_LEFT) : 'Sin punto de venta', 'monto' => (float) ($a?->monto ?? 0), 'n' => (int) ($a?->n ?? 0), 'anterior' => (float) ($b?->monto ?? 0), 'n_anterior' => (int) ($b?->n ?? 0), 'var' => self::pct((float) ($a?->monto ?? 0), (float) ($b?->monto ?? 0))];
        }
        usort($out, fn($x, $y) => $y['monto'] <=> $x['monto']);
        return ['items' => $out, 'como' => self::COMO['puntos']];
    }

    // Lo que se frenó de golpe: se vendía seguido, tiene stock, y hace 14 días que no sale nada.
    public function frenados(?int $sucursal = null, ?Carbon $hoy = null, int $limite = 50): array
    {
        $hoy ??= today(); $corte = $hoy->copy()->subDays(14); $ini = $corte->copy()->subWeeks(6);
        $sem = Sql::driver() === 'pgsql' ? "to_char(comprobantes.fecha, 'IYYY-IW')" : (Sql::driver() === 'sqlite' ? "strftime('%Y-%W', comprobantes.fecha)" : "DATE_FORMAT(comprobantes.fecha, '%x-%v')");
        $antes = $this->items($ini->toDateString(), $corte->copy()->subDay()->toDateString(), $sucursal)->whereIn('comprobantes.tipo', self::FACTURAS)
            ->selectRaw("comprobante_items.product_id as pid, COUNT(DISTINCT {$sem}) as semanas, SUM(comprobante_items.cantidad) as unidades")->groupBy('comprobante_items.product_id')->havingRaw("COUNT(DISTINCT {$sem}) >= 3")->get();
        if ($antes->isEmpty()) return ['items' => [], 'como' => self::COMO['frenados']];
        $recientes = $this->items($corte->toDateString(), $hoy->toDateString(), $sucursal)->whereIn('comprobantes.tipo', self::FACTURAS)->whereIn('comprobante_items.product_id', $antes->pluck('pid'))->distinct()->pluck('comprobante_items.product_id')->all();
        $ids = $antes->pluck('pid')->diff($recientes);
        $prods = Product::whereIn('id', $ids)->where('active', true)->where('stock', '>', 0)->get()->keyBy('id');
        $out = $antes->filter(fn($a) => isset($prods[$a->pid]))->map(fn($a) => ['id' => $a->pid, 'nombre' => $prods[$a->pid]->name, 'sku' => $prods[$a->pid]->sku, 'stock' => (float) $prods[$a->pid]->stock, 'semanas' => (int) $a->semanas, 'por_semana' => round((float) $a->unidades / 6, 1), 'precio' => (float) $prods[$a->pid]->price, 'actualizado' => $prods[$a->pid]->precio_actualizado_en?->toDateString()])
            ->sortByDesc('por_semana')->take($limite)->values()->all();
        return ['items' => $out, 'como' => self::COMO['frenados']];
    }

    // Cada vendedor en el período, con sus destacados.
    public function vendedores(Carbon $desde, Carbon $hasta, ?int $sucursal = null): array
    {
        $dias = $desde->diffInDays($hasta) + 1;
        $g = fn(string $d, string $h) => $this->ventas($d, $h, $sucursal)->leftJoin('vendedores', 'vendedores.id', '=', 'comprobantes.vendedor_id')->leftJoin('users', 'users.id', '=', 'comprobantes.user_id')
            ->selectRaw("COALESCE(vendedores.nombre, users.name, 'Sin vendedor') as nombre, COALESCE(comprobantes.vendedor_id, 0) as vid, SUM((" . self::SIGNO . ") * comprobantes.total) as monto, SUM(CASE WHEN comprobantes.tipo IN ('FA','FB','FC','FE') THEN 1 ELSE 0 END) as n, COUNT(DISTINCT comprobantes.contact_id) as clientes, SUM(CASE WHEN comprobantes.tipo IN ('NCA','NCB','NCC') THEN comprobantes.total ELSE 0 END) as nc, SUM((" . self::SIGNO . ") * (comprobantes.neto - comprobantes.descuento)) as neto")
            ->groupByRaw("COALESCE(vendedores.nombre, users.name, 'Sin vendedor'), COALESCE(comprobantes.vendedor_id, 0)")->get()->keyBy(fn($r) => $r->vid . '|' . $r->nombre);
        $act = $g($desde->toDateString(), $hasta->toDateString()); $prev = $g($desde->copy()->subDays($dias)->toDateString(), $hasta->copy()->subDays($dias)->toDateString());
        $total = (float) $act->sum('monto');
        // margen por vendedor con el costo del día de la venta, y la hora pico
        $margen = $this->items($desde->toDateString(), $hasta->toDateString(), $sucursal)->selectRaw('COALESCE(comprobantes.vendedor_id, 0) as vid, SUM((' . self::SIGNO . ') * (comprobante_items.neto - comprobante_items.descuento - comprobante_items.cantidad * COALESCE(comprobante_items.costo_unit, 0))) as m')->groupBy('vid')->pluck('m', 'vid');
        $horas = $this->ventas($desde->toDateString(), $hasta->toDateString(), $sucursal)->whereNotNull('emitido_en')->selectRaw('COALESCE(comprobantes.vendedor_id, 0) as vid, ' . Sql::hora('comprobantes.emitido_en') . ' as h, COUNT(*) as n')->groupBy('vid', 'h')->get()->groupBy('vid')->map(fn($g) => $g->sortByDesc('n')->first()?->h);
        $out = $act->map(function ($r) use ($prev, $total, $margen, $horas) {
            $p = (float) ($prev[$r->vid . '|' . $r->nombre]->monto ?? 0);
            return ['nombre' => $r->nombre, 'vendedor_id' => (int) $r->vid ?: null, 'ventas' => (float) $r->monto, 'anterior' => $p, 'var' => self::pct((float) $r->monto, $p), 'parte' => $total > 0 ? round((float) $r->monto / $total * 100, 1) : 0, 'facturas' => (int) $r->n, 'ticket' => (int) $r->n ? round((float) $r->monto / (int) $r->n, 2) : 0, 'clientes' => (int) $r->clientes, 'nc' => (float) $r->nc,
                'margen' => round((float) ($margen[$r->vid] ?? 0), 2), 'margen_pct' => (float) $r->neto > 0 ? round((float) ($margen[$r->vid] ?? 0) / (float) $r->neto * 100, 1) : null, 'hora_pico' => isset($horas[$r->vid]) ? (int) $horas[$r->vid] : null];
        })->sortByDesc('ventas')->values();
        $dest = fn($k) => $out->filter(fn($v) => $v[$k] !== null)->sortByDesc($k)->first();
        return ['items' => $out->all(), 'total' => round($total, 2), 'destacados' => ['mas_vendio' => $dest('ventas'), 'mejor_margen' => $dest('margen_pct'), 'mejor_ticket' => $dest('ticket'), 'mas_crecio' => $dest('var')], 'como' => self::COMO['vendedores']];
    }
}
