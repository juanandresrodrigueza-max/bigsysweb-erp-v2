<?php

namespace App\Services\Estadisticas;

use App\Models\ActivoAmortizacion;
use App\Models\Business;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\ExpenseCategory;
use App\Models\Liquidacion;
use App\Models\MovimientoFondos;
use App\Services\Comprobantes\ComisionesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Rentabilidad real: ventas netas − costo de lo vendido = margen bruto; − costos variables = margen de contribución; − costos fijos = resultado.
// Los costos salen de: gastos de fondos (por categoría clasificada), compras no inventariables, comisiones de vendedores, sueldos y cargas, amortizaciones.
class RentabilidadService
{
    public const DEFAULT = ['distribuir_indirectos' => true, 'compras_gastos' => 'variable', 'sin_categoria' => 'fijo'];
    private const NC = ['NCA', 'NCB', 'NCC'];
    private const TIPOS_VENTA = ['FA', 'FB', 'FC', 'FE', 'NDA', 'NDB', 'NDC', 'NCA', 'NCB', 'NCC'];

    public function __construct(private ComisionesService $comisiones) {}

    public function config(Business $b): array
    {
        return array_merge(self::DEFAULT, $b->rentabilidad ?? []);
    }

    public function calcular(Business $b, Carbon $desde, Carbon $hasta, ?int $sucursal = null): array
    {
        $cfg = $this->config($b);
        [$d, $h] = [$desde->toDateString(), $hasta->toDateString()];
        $signo = 'CASE WHEN comprobantes.tipo IN (\'NCA\',\'NCB\',\'NCC\') THEN -1 ELSE 1 END';

        // ---- Ventas y costo de lo vendido -------------------------------------------------
        $ventasQ = fn() => Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', self::TIPOS_VENTA)->whereBetween('fecha', [$d, $h])->when($sucursal, fn($q) => $q->where('business_location_id', $sucursal));
        $ventas = round((float) $ventasQ()->selectRaw("COALESCE(SUM(({$signo}) * (neto - descuento + exento)),0) s")->value('s'), 2);
        $comprobantes = (int) $ventasQ()->whereNotIn('tipo', self::NC)->count();
        $items = fn() => ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->leftJoin('products', 'products.id', '=', 'comprobante_items.product_id')
            ->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', self::TIPOS_VENTA)->whereBetween('comprobantes.fecha', [$d, $h])->when($sucursal, fn($q) => $q->where('comprobantes.business_location_id', $sucursal));
        $costoExpr = "({$signo}) * comprobante_items.cantidad * COALESCE(comprobante_items.costo_unit, products.cost, 0)";
        $netoExpr = "({$signo}) * comprobante_items.neto";
        $cmv = round((float) $items()->selectRaw("COALESCE(SUM({$costoExpr}),0) s")->value('s'), 2);
        $margenBruto = round($ventas - $cmv, 2);

        // ---- Gastos por categoría (fondos) ---------------------------------------------------
        $gastosQ = MovimientoFondos::withoutGlobalScopes()->where('movimientos_fondos.business_id', $b->id)->where('origen', 'gasto')->whereBetween('fecha', [$d, $h])
            ->when($sucursal, fn($q) => $q->join('cuentas_fondos', 'cuentas_fondos.id', '=', 'movimientos_fondos.cuenta_fondos_id')->where(fn($w) => $w->where('cuentas_fondos.business_location_id', $sucursal)->orWhereNull('cuentas_fondos.business_location_id')))
            ->selectRaw('expense_category_id, SUM(movimientos_fondos.egreso * COALESCE(movimientos_fondos.cotizacion, 1)) as monto, COUNT(*) as n')->groupBy('expense_category_id')->get();
        $cats = ExpenseCategory::withoutGlobalScopes()->where('business_id', $b->id)->get()->keyBy('id');
        $lineas = []; // cada costo: nombre, monto, tipo (fijo|variable), imputacion, origen
        $sinCategoria = 0;
        foreach ($gastosQ as $g) {
            $cat = $g->expense_category_id ? $cats->get($g->expense_category_id) : null;
            if (! $cat) { $sinCategoria += (float) $g->monto; continue; }
            $lineas[] = ['nombre' => $cat->name, 'monto' => round((float) $g->monto, 2), 'tipo' => $cat->tipo_costo ?? 'fijo', 'imputacion' => $cat->imputacion ?? 'indirecto', 'origen' => 'gasto', 'categoria_id' => $cat->id, 'color' => $cat->color, 'n' => (int) $g->n];
        }
        if ($sinCategoria > 0.005) $lineas[] = ['nombre' => 'Gastos sin categoría', 'monto' => round($sinCategoria, 2), 'tipo' => $cfg['sin_categoria'], 'imputacion' => 'indirecto', 'origen' => 'gasto', 'categoria_id' => null, 'color' => '#9a948c', 'n' => 0];

        // Compras no inventariables (facturas de compra con ítems sin artículo o sin control de stock)
        $sigC = 'CASE WHEN comprobantes.tipo IN (\'NCA\',\'NCB\',\'NCC\') THEN -1 ELSE 1 END';
        $comprasGastos = round((float) ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->leftJoin('products', 'products.id', '=', 'comprobante_items.product_id')
            ->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'compra')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', self::TIPOS_VENTA)->whereBetween('comprobantes.fecha', [$d, $h])
            ->when($sucursal, fn($q) => $q->where('comprobantes.business_location_id', $sucursal))
            ->where(fn($w) => $w->whereNull('comprobante_items.product_id')->orWhere('products.controla_stock', false))
            ->selectRaw("COALESCE(SUM(({$sigC}) * comprobante_items.neto),0) s")->value('s'), 2);
        if ($comprasGastos > 0.005) $lineas[] = ['nombre' => 'Compras no inventariables', 'monto' => $comprasGastos, 'tipo' => $cfg['compras_gastos'], 'imputacion' => 'indirecto', 'origen' => 'compra', 'categoria_id' => null, 'color' => '#c77d00', 'n' => 0];

        // Comisiones de vendedores (variable y directo por vendedor)
        $comis = collect($this->comisionesEmpresa($b, $d, $h));
        $comisTotal = round((float) $comis->sum('total'), 2);
        if ($comisTotal > 0.005) $lineas[] = ['nombre' => 'Comisiones de vendedores', 'monto' => $comisTotal, 'tipo' => 'variable', 'imputacion' => 'directo', 'origen' => 'comisiones', 'categoria_id' => null, 'color' => '#a42785', 'n' => $comis->count()];

        // Sueldos y cargas (liquidaciones confirmadas del período) — fijo indirecto
        $liq = Liquidacion::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', '!=', 'borrador')->whereBetween('fecha', [$d, $h]);
        $sueldos = round((float) $liq->clone()->selectRaw('COALESCE(SUM(total_bruto + total_no_rem),0) s')->value('s'), 2);
        $cargas = round((float) $liq->clone()->selectRaw('COALESCE(SUM(total_contribuciones),0) s')->value('s'), 2);
        if ($sueldos > 0.005) $lineas[] = ['nombre' => 'Sueldos', 'monto' => $sueldos, 'tipo' => 'fijo', 'imputacion' => 'indirecto', 'origen' => 'sueldos', 'categoria_id' => null, 'color' => '#4f3089', 'n' => 0];
        if ($cargas > 0.005) $lineas[] = ['nombre' => 'Cargas sociales', 'monto' => $cargas, 'tipo' => 'fijo', 'imputacion' => 'indirecto', 'origen' => 'sueldos', 'categoria_id' => null, 'color' => '#6b4fb0', 'n' => 0];

        // Amortizaciones de bienes de uso (por período mensual) — fijo indirecto
        $amort = round((float) ActivoAmortizacion::join('activos_fijos', 'activos_fijos.id', '=', 'activo_amortizaciones.activo_fijo_id')->where('activos_fijos.business_id', $b->id)
            ->whereBetween('activo_amortizaciones.periodo', [$desde->format('Y-m'), $hasta->format('Y-m')])->sum('activo_amortizaciones.monto'), 2);
        if ($amort > 0.005) $lineas[] = ['nombre' => 'Amortizaciones', 'monto' => $amort, 'tipo' => 'fijo', 'imputacion' => 'indirecto', 'origen' => 'amortizaciones', 'categoria_id' => null, 'color' => '#7a7470', 'n' => 0];

        // Si la categoría "Sueldos" de fondos también se usa (pagos manuales), evitamos contar doble: si hay liquidaciones, los gastos de esa categoría se informan pero no se suman.
        $dobles = [];
        if ($sueldos > 0.005) {
            foreach ($lineas as $i => $l) if ($l['origen'] === 'gasto' && preg_match('/sueldo|jornal|haberes/i', $l['nombre'])) { $lineas[$i]['excluido'] = true; $dobles[] = $l['nombre']; }
        }
        $activas = array_values(array_filter($lineas, fn($l) => empty($l['excluido'])));

        $variables = round(array_sum(array_map(fn($l) => $l['monto'], array_filter($activas, fn($l) => $l['tipo'] === 'variable'))), 2);
        $fijos = round(array_sum(array_map(fn($l) => $l['monto'], array_filter($activas, fn($l) => $l['tipo'] === 'fijo'))), 2);
        $directos = round(array_sum(array_map(fn($l) => $l['monto'], array_filter($activas, fn($l) => $l['imputacion'] === 'directo'))), 2);
        $indirectos = round(array_sum(array_map(fn($l) => $l['monto'], array_filter($activas, fn($l) => $l['imputacion'] === 'indirecto'))), 2);
        $margenContrib = round($margenBruto - $variables, 2);
        $resultado = round($margenContrib - $fijos, 2);
        $ratioContrib = $ventas > 0 ? $margenContrib / $ventas : 0;
        $puntoEquilibrio = $ratioContrib > 0 ? round($fijos / $ratioContrib, 2) : null;
        $dias = max(1, (int) round($desde->diffInDays($hasta)) + 1);

        $kpis = [
            'ventas' => $ventas, 'comprobantes' => $comprobantes, 'cmv' => $cmv, 'margen_bruto' => $margenBruto, 'margen_bruto_pct' => $ventas > 0 ? round($margenBruto / $ventas * 100, 1) : null,
            'variables' => $variables, 'margen_contribucion' => $margenContrib, 'margen_contribucion_pct' => $ventas > 0 ? round($ratioContrib * 100, 1) : null,
            'fijos' => $fijos, 'resultado' => $resultado, 'resultado_pct' => $ventas > 0 ? round($resultado / $ventas * 100, 1) : null,
            'directos' => $directos, 'indirectos' => $indirectos, 'costos_total' => round($cmv + $variables + $fijos, 2),
            'punto_equilibrio' => $puntoEquilibrio, 'punto_equilibrio_diario' => $puntoEquilibrio !== null ? round($puntoEquilibrio / $dias, 2) : null, 'ventas_diarias' => round($ventas / $dias, 2),
            'cobertura_pct' => $puntoEquilibrio ? round($ventas / $puntoEquilibrio * 100, 1) : null, 'dias' => $dias,
            'fijos_diarios' => round($fijos / $dias, 2),
        ];

        // ---- Por dimensión ---------------------------------------------------------------------
        // Costos indirectos se reparten proporcional a las ventas de cada fila (si está activado). Los directos por vendedor (comisiones) van a su vendedor.
        $indirectosARepartir = $cfg['distribuir_indirectos'] ? round($indirectos, 2) : 0;
        $dim = function (string $campo, string $join, string $nombre, string $groupBy = null) use ($items, $costoExpr, $netoExpr, $signo, $ventas, $indirectosARepartir) {
            $q = $items();
            if ($join) $q->leftJoin(...explode('|', $join));
            $rows = $q->selectRaw("{$campo} as clave, {$nombre} as nombre, SUM({$netoExpr}) as neto, SUM({$costoExpr}) as costo, SUM(({$signo}) * comprobante_items.cantidad) as cantidad")->groupBy(DB::raw($groupBy ?: $campo . ', ' . $nombre))->orderByDesc('neto')->get();
            return $rows->map(function ($r) use ($ventas, $indirectosARepartir) {
                $neto = round((float) $r->neto, 2); $costo = round((float) $r->costo, 2); $mb = round($neto - $costo, 2);
                $ind = $ventas > 0 ? round($indirectosARepartir * $neto / $ventas, 2) : 0;
                return ['clave' => $r->clave, 'nombre' => $r->nombre ?: 'Sin asignar', 'cantidad' => round((float) $r->cantidad, 3), 'ventas' => $neto, 'costo' => $costo, 'margen_bruto' => $mb, 'margen_pct' => $neto > 0 ? round($mb / $neto * 100, 1) : null, 'indirectos' => $ind, 'resultado' => round($mb - $ind, 2), 'participacion' => $ventas > 0 ? round($neto / $ventas * 100, 1) : 0];
            })->values()->all();
        };
        $por = [
            'articulos' => $dim('comprobante_items.product_id', '', 'COALESCE(products.name, comprobante_items.descripcion)'),
            'rubros' => $dim('products.rubro_id', 'rubros|rubros.id|=|products.rubro_id', 'COALESCE(rubros.nombre, \'Sin rubro\')'),
            'clientes' => $dim('comprobantes.contact_id', 'contacts|contacts.id|=|comprobantes.contact_id', 'contacts.name'),
            'vendedores' => $dim('comprobantes.vendedor_id', 'vendedores|vendedores.id|=|comprobantes.vendedor_id', 'COALESCE(vendedores.nombre, \'Sin vendedor\')'),
            'sucursales' => $dim('comprobantes.business_location_id', 'business_locations|business_locations.id|=|comprobantes.business_location_id', 'business_locations.name'),
            'obras' => $dim('comprobantes.proyecto_id', 'proyectos|proyectos.id|=|comprobantes.proyecto_id', 'COALESCE(proyectos.nombre, \'Sin obra\')'),
        ];
        // Comisiones: costo directo del vendedor.
        $comisPorVend = $comis->keyBy('id');
        foreach ($por['vendedores'] as &$v) {
            $c = $v['clave'] ? (float) ($comisPorVend->get($v['clave'])['total'] ?? 0) : 0;
            $v['directos'] = round($c, 2); $v['resultado'] = round($v['resultado'] - $c, 2);
        }
        unset($v);

        // ---- Serie mensual (últimos 12 meses, en pesos) -----------------------------------------
        $serie = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->subMonths($i); $md = $m->copy()->startOfMonth()->toDateString(); $mh = $m->copy()->endOfMonth()->toDateString();
            $vq = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->whereIn('tipo', self::TIPOS_VENTA)->whereBetween('fecha', [$md, $mh])->when($sucursal, fn($q) => $q->where('business_location_id', $sucursal));
            $v = round((float) $vq->selectRaw("COALESCE(SUM(({$signo}) * (neto - descuento + exento)),0) s")->value('s'), 2);
            $c = round((float) ComprobanteItem::join('comprobantes', 'comprobantes.id', '=', 'comprobante_items.comprobante_id')->leftJoin('products', 'products.id', '=', 'comprobante_items.product_id')->where('comprobantes.business_id', $b->id)->where('comprobantes.direccion', 'venta')->where('comprobantes.estado', 'emitido')->whereIn('comprobantes.tipo', self::TIPOS_VENTA)->whereBetween('comprobantes.fecha', [$md, $mh])->when($sucursal, fn($q) => $q->where('comprobantes.business_location_id', $sucursal))->selectRaw("COALESCE(SUM({$costoExpr}),0) s")->value('s'), 2);
            $g = $this->costosMes($b, $md, $mh, $m->format('Y-m'), $cats, $cfg);
            $serie[] = ['mes' => $m->locale('es')->isoFormat('MMM YY'), 'ventas' => $v, 'margen_bruto' => round($v - $c, 2), 'variables' => $g['variables'], 'fijos' => $g['fijos'], 'resultado' => round($v - $c - $g['variables'] - $g['fijos'], 2)];
        }

        $sinClasificar = $cats->filter(fn($c) => ! $c->tipo_costo || ! $c->imputacion)->values();
        return [
            'kpis' => $kpis, 'lineas' => $lineas, 'dobles' => $dobles, 'por' => $por, 'serie' => $serie, 'config' => $cfg,
            'categorias' => $cats->sortBy('name')->values()->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'tipo_costo' => $c->tipo_costo, 'imputacion' => $c->imputacion, 'sugerencia' => ExpenseCategory::sugerir($c->name)])->all(),
            'sin_clasificar' => $sinClasificar->count(),
        ];
    }

    // Costos (sin CMV) de un mes: gastos por categoría + comisiones + sueldos + amortizaciones. Para la serie.
    private function costosMes(Business $b, string $d, string $h, string $periodo, $cats, array $cfg): array
    {
        $variables = 0; $fijos = 0;
        foreach (MovimientoFondos::withoutGlobalScopes()->where('business_id', $b->id)->where('origen', 'gasto')->whereBetween('fecha', [$d, $h])->selectRaw('expense_category_id, SUM(egreso * COALESCE(cotizacion,1)) as monto')->groupBy('expense_category_id')->get() as $g) {
            $cat = $g->expense_category_id ? $cats->get($g->expense_category_id) : null;
            $tipo = $cat ? ($cat->tipo_costo ?? 'fijo') : $cfg['sin_categoria'];
            if ($tipo === 'variable') $variables += (float) $g->monto; else $fijos += (float) $g->monto;
        }
        $com = array_sum(array_column($this->comisionesEmpresa($b, $d, $h), 'total'));
        $variables += $com;
        $liq = Liquidacion::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', '!=', 'borrador')->whereBetween('fecha', [$d, $h]);
        $fijos += (float) $liq->selectRaw('COALESCE(SUM(total_bruto + total_no_rem + total_contribuciones),0) s')->value('s');
        $fijos += (float) ActivoAmortizacion::join('activos_fijos', 'activos_fijos.id', '=', 'activo_amortizaciones.activo_fijo_id')->where('activos_fijos.business_id', $b->id)->where('activo_amortizaciones.periodo', $periodo)->sum('activo_amortizaciones.monto');
        return ['variables' => round($variables, 2), 'fijos' => round($fijos, 2)];
    }

    // Comisiones sin depender del scope global (el servicio de comisiones usa el usuario autenticado).
    private function comisionesEmpresa(Business $b, string $d, string $h): array
    {
        $out = [];
        foreach (\App\Models\Vendedor::withoutGlobalScopes()->where('business_id', $b->id)->get() as $v) {
            $ventas = Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->where('estado', 'emitido')->where('vendedor_id', $v->id)->whereBetween('fecha', [$d, $h])->whereIn('tipo', self::TIPOS_VENTA)->get();
            $facturado = round($ventas->sum(fn($c) => $c->def()['cc'] * (float) $c->neto), 2);
            $cobrado = round((float) \App\Models\Cobro::withoutGlobalScopes()->where('business_id', $b->id)->where('vendedor_id', $v->id)->where('estado', '!=', 'anulado')->whereBetween('fecha', [$d, $h])->sum('total'), 2);
            $total = round($facturado * (float) $v->comision_venta / 100 + $cobrado * (float) $v->comision_cobro / 100, 2);
            if ($total > 0.005) $out[] = ['id' => $v->id, 'nombre' => $v->nombre, 'total' => $total];
        }
        return $out;
    }

    public function csv(array $r, string $desde, string $hasta): string
    {
        $n = fn($v) => number_format((float) $v, 2, ',', '');
        $csv = "Rentabilidad {$desde} a {$hasta}\n\nConcepto;Monto\n";
        $k = $r['kpis'];
        foreach (['Ventas netas' => 'ventas', 'Costo de lo vendido' => 'cmv', 'Margen bruto' => 'margen_bruto', 'Costos variables' => 'variables', 'Margen de contribución' => 'margen_contribucion', 'Costos fijos' => 'fijos', 'Resultado' => 'resultado', 'Punto de equilibrio' => 'punto_equilibrio'] as $l => $key) $csv .= "{$l};{$n($k[$key])}\n";
        $csv .= "\nCosto;Tipo;Imputación;Monto\n";
        foreach ($r['lineas'] as $l) $csv .= str_replace(';', ',', $l['nombre']) . ";{$l['tipo']};{$l['imputacion']};{$n($l['monto'])}" . (empty($l['excluido']) ? '' : ';no sumado') . "\n";
        foreach ($r['por'] as $dim => $rows) {
            $csv .= "\n" . ucfirst($dim) . ";Ventas;Costo;Margen bruto;Margen %;Indirectos;Resultado\n";
            foreach ($rows as $x) $csv .= str_replace(';', ',', (string) $x['nombre']) . ";{$n($x['ventas'])};{$n($x['costo'])};{$n($x['margen_bruto'])};" . ($x['margen_pct'] ?? '') . ";{$n($x['indirectos'])};{$n($x['resultado'])}\n";
        }
        return "\xEF\xBB\xBF" . $csv;
    }
}
