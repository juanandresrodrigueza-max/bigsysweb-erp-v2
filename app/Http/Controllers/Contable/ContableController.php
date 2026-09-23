<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Asiento;
use App\Models\AsientoLinea;
use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Models\CuentaContable;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Services\Contabilidad\ContabilidadService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// Contabilidad: resumen, asientos, plan de cuentas, mayor, libros IVA, balance y flujo de fondos.
class ContableController extends Controller
{
    private function periodo(Request $request): array
    {
        $desde = $request->desde ? Carbon::parse($request->desde) : now()->startOfMonth();
        $hasta = $request->hasta ? Carbon::parse($request->hasta) : now()->endOfMonth();
        return [$desde->toDateString(), $hasta->toDateString()];
    }

    public function index(Request $request, ContabilidadService $svc)
    {
        $b = $request->user()->business_id;
        [$desde, $hasta] = $this->periodo($request);
        $res = $svc->resultado($b, $desde, $hasta);

        // Ingresos vs egresos últimos 6 meses (para la barra).
        $meses = collect(range(5, 0))->map(function ($i) use ($svc, $b) {
            $m = now()->subMonths($i);
            $r = $svc->resultado($b, $m->copy()->startOfMonth()->toDateString(), $m->copy()->endOfMonth()->toDateString());
            return ['mes' => $m->locale('es')->isoFormat('MMM'), 'ingresos' => $r['total_ingresos'], 'egresos' => $r['total_egresos'], 'resultado' => $r['resultado']];
        });

        $saldo = fn($clave) => $this->saldoClave($b, $clave);
        return Inertia::render('Contable/Index', [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta], 'resultado' => $res, 'meses' => $meses,
            'posicion' => [
                'caja' => $saldo('caja') + $saldo('banco') + $saldo('billetera'), 'cheques' => $saldo('cheques_cartera'), 'deudores' => $saldo('deudores'), 'mercaderias' => $saldo('mercaderias'),
                'proveedores' => -$saldo('proveedores'), 'cheques_propios' => -$saldo('cheques_propios'), 'iva' => -($saldo('iva_df') + $saldo('iva_cf')),
            ],
            'asientos' => Asiento::count(), 'ultimo' => Asiento::confirmados()->latest('fecha')->latest('id')->first()?->fecha?->format('d/m/Y'),
            'pendientes' => $this->pendientes($b),
        ]);
    }

    // Saldo deudor (+) / acreedor (−) de una cuenta por clave, histórico.
    private function saldoClave(int $b, string $clave): float
    {
        $c = CuentaContable::withoutGlobalScopes()->where('business_id', $b)->where('clave', $clave)->first();
        if (! $c) return 0;
        $r = DB::table('asiento_lineas')->join('asientos', 'asientos.id', '=', 'asiento_lineas.asiento_id')->where('asientos.business_id', $b)->where('asientos.estado', 'confirmado')->where('cuenta_id', $c->id)->selectRaw('COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h')->first();
        return round((float) $r->d - (float) $r->h, 2);
    }

    private function pendientes(int $b): int
    {
        $tiene = fn($origen) => Asiento::withoutGlobalScopes()->where('business_id', $b)->where('origen', $origen)->select('origen_id');
        return Comprobante::where('estado', 'emitido')->whereIn('tipo', array_keys(array_filter(Comprobante::TIPOS, fn($t) => $t['cc'] !== 0)))->where(fn($q) => $q->where('direccion', 'venta')->whereNotIn('id', $tiene('venta'))->orWhere(fn($w) => $w->where('direccion', 'compra')->whereNotIn('id', $tiene('compra'))))->count()
            + \App\Models\Cobro::where('estado', '!=', 'anulado')->whereNotIn('id', $tiene('cobro'))->count()
            + \App\Models\Pago::where('estado', '!=', 'anulado')->whereNotIn('id', $tiene('pago'))->count()
            + MovimientoFondos::whereIn('origen', ['gasto', 'ingreso', 'transferencia', 'ajuste', 'apertura', 'cheque'])->whereNotIn('id', $tiene('fondos'))->count();
    }

    public function sincronizar(Request $request, ContabilidadService $svc)
    {
        $n = $svc->sincronizar($request->user()->business_id);
        return back()->with('success', $n ? "Se generaron {$n} asientos." : 'No había operaciones sin contabilizar.');
    }

    public function asientos(Request $request)
    {
        [$desde, $hasta] = $this->periodo($request);
        $q = Asiento::with('lineas.cuenta:id,codigo,nombre', 'user:id,name')->whereBetween('fecha', [$desde, $hasta])
            ->when($request->origen, fn($q, $o) => $q->where('origen', $o))
            ->when($request->buscar, fn($q, $s) => $q->where('concepto', 'like', "%$s%"))
            ->when($request->estado === 'anulado', fn($q) => $q->where('estado', 'anulado'), fn($q) => $q->where('estado', 'confirmado'))
            ->orderByDesc('fecha')->orderByDesc('numero');
        return Inertia::render('Contable/Asientos', [
            'lista' => $q->paginate(30)->withQueryString()->through(fn($a) => ['id' => $a->id, 'numero' => $a->numeroFormateado(), 'fecha' => $a->fecha->format('d/m/Y'), 'concepto' => $a->concepto, 'origen' => $a->origen, 'origen_label' => Asiento::ORIGENES[$a->origen] ?? $a->origen, 'total' => (float) $a->total, 'estado' => $a->estado, 'usuario' => $a->user?->name, 'url' => $a->urlOrigen(),
                'lineas' => $a->lineas->map(fn($l) => ['cuenta' => $l->cuenta?->codigo . ' ' . $l->cuenta?->nombre, 'cuenta_id' => $l->cuenta_id, 'debe' => (float) $l->debe, 'haber' => (float) $l->haber, 'detalle' => $l->detalle])]),
            'periodo' => ['desde' => $desde, 'hasta' => $hasta], 'filtros' => $request->only('origen', 'buscar', 'estado'), 'origenes' => Asiento::ORIGENES,
            'cuentas' => CuentaContable::where('imputable', true)->where('activa', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function guardarAsiento(Request $request, ContabilidadService $svc)
    {
        $d = $request->validate(['fecha' => 'required|date', 'concepto' => 'required|string|max:200', 'lineas' => 'required|array|min:2', 'lineas.*.cuenta_id' => 'required|exists:cuentas_contables,id', 'lineas.*.debe' => 'nullable|numeric|min:0', 'lineas.*.haber' => 'nullable|numeric|min:0', 'lineas.*.detalle' => 'nullable|string|max:150']);
        $a = $svc->manual($d);
        return back()->with('success', "Asiento {$a->numeroFormateado()} registrado.");
    }

    public function anularAsiento(Request $request, int $id)
    {
        $a = Asiento::findOrFail($id);
        abort_if($a->origen !== 'manual', 422, 'Los asientos automáticos se anulan anulando la operación que los generó.');
        $a->update(['estado' => 'anulado']);
        AuditLog::registrar('anular', $a, "Anuló asiento {$a->numeroFormateado()}");
        return back()->with('success', 'Asiento anulado.');
    }

    public function plan(Request $request, ContabilidadService $svc)
    {
        $b = $request->user()->business_id;
        $sumas = $svc->sumas($b, null, null);
        $cuentas = CuentaContable::orderBy('codigo')->get()->map(function ($c) use ($sumas) {
            $s = $sumas[$c->id] ?? ['debe' => 0, 'haber' => 0];
            return ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'clave' => $c->clave, 'imputable' => $c->imputable, 'activa' => $c->activa, 'parent_id' => $c->parent_id, 'nivel' => substr_count($c->codigo, '.'), 'saldo' => round($c->naturalezaDeudora() ? $s['debe'] - $s['haber'] : $s['haber'] - $s['debe'], 2), 'movimientos' => ($s['debe'] || $s['haber'])];
        });
        return Inertia::render('Contable/Plan', ['cuentas' => $cuentas, 'tipos' => CuentaContable::TIPOS]);
    }

    public function guardarCuenta(Request $request, ?int $id = null)
    {
        $d = $request->validate(['codigo' => 'required|string|max:20', 'nombre' => 'required|string|max:120', 'tipo' => 'required|in:activo,pasivo,patrimonio,ingreso,egreso', 'parent_id' => 'nullable|exists:cuentas_contables,id', 'imputable' => 'boolean', 'activa' => 'boolean']);
        $b = $request->user()->business_id;
        abort_if(CuentaContable::where('codigo', $d['codigo'])->where('id', '!=', $id ?? 0)->exists(), 422, "Ya existe la cuenta {$d['codigo']}.");
        $c = $id ? CuentaContable::findOrFail($id) : new CuentaContable(['business_id' => $b]);
        $c->fill($d)->save();
        AuditLog::registrar($id ? 'editar' : 'crear', $c, "Cuenta {$c->codigo} {$c->nombre}");
        return back()->with('success', 'Cuenta guardada.');
    }

    public function mayor(Request $request)
    {
        [$desde, $hasta] = $this->periodo($request);
        $cuentas = CuentaContable::where('imputable', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'tipo']);
        $cuentaId = (int) ($request->cuenta ?: ($cuentas->firstWhere('codigo', '1.1.02')?->id ?? $cuentas->first()?->id));
        $cuenta = $cuentas->firstWhere('id', $cuentaId);
        $deudora = in_array($cuenta?->tipo, ['activo', 'egreso'], true);
        $ini = DB::table('asiento_lineas')->join('asientos', 'asientos.id', '=', 'asiento_lineas.asiento_id')->where('asientos.business_id', $request->user()->business_id)->where('asientos.estado', 'confirmado')->where('cuenta_id', $cuentaId)->where('asientos.fecha', '<', $desde)->selectRaw('COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h')->first();
        $saldo = $deudora ? (float) $ini->d - (float) $ini->h : (float) $ini->h - (float) $ini->d;
        $inicial = $saldo;
        $movs = AsientoLinea::with('asiento:id,numero,fecha,concepto,origen,origen_id', 'contact:id,name')->where('cuenta_id', $cuentaId)->whereHas('asiento', fn($q) => $q->where('estado', 'confirmado')->whereBetween('fecha', [$desde, $hasta]))
            ->get()->sortBy(fn($l) => [$l->asiento->fecha->toDateString(), $l->asiento->numero])->values()
            ->map(function ($l) use (&$saldo, $deudora) { $saldo += $deudora ? (float) $l->debe - (float) $l->haber : (float) $l->haber - (float) $l->debe; return ['id' => $l->id, 'fecha' => $l->asiento->fecha->format('d/m/Y'), 'asiento' => $l->asiento->numeroFormateado(), 'concepto' => $l->asiento->concepto, 'detalle' => $l->detalle, 'contacto' => $l->contact?->name, 'debe' => (float) $l->debe, 'haber' => (float) $l->haber, 'saldo' => round($saldo, 2), 'url' => $l->asiento->urlOrigen()]; });
        return Inertia::render('Contable/Mayor', ['cuentas' => $cuentas, 'cuentaId' => $cuentaId, 'cuenta' => $cuenta, 'periodo' => ['desde' => $desde, 'hasta' => $hasta], 'inicial' => round($inicial, 2), 'movimientos' => $movs, 'final' => round($saldo, 2), 'deudora' => $deudora]);
    }

    public function iva(Request $request)
    {
        [$desde, $hasta] = $this->periodo($request);
        $libro = $request->libro === 'compras' ? 'compras' : 'ventas';
        $filas = $this->filasIva($libro, $desde, $hasta);
        $tot = ['neto' => 0, 'iva' => 0, 'exento' => 0, 'percepciones' => 0, 'total' => 0, 'iva_21' => 0, 'iva_105' => 0, 'iva_27' => 0];
        foreach ($filas as $f) foreach ($tot as $k => $v) $tot[$k] = round($v + $f[$k], 2);
        if ($request->export) {
            $csv = "Fecha;Tipo;Número;" . ($libro === 'ventas' ? 'Cliente' : 'Proveedor') . ";CUIT;Cond. IVA;Neto gravado;IVA 21%;IVA 10,5%;IVA 27%;IVA total;Exento;Percepciones;Total\n";
            foreach ($filas as $f) $csv .= implode(';', [$f['fecha'], $f['tipo'], $f['numero'], str_replace(';', ',', $f['contacto']), $f['cuit'], $f['condicion'], ...array_map(fn($k) => number_format($f[$k], 2, ',', ''), ['neto', 'iva_21', 'iva_105', 'iva_27', 'iva', 'exento', 'percepciones', 'total'])]) . "\n";
            return response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=libro_iva_{$libro}_{$desde}_{$hasta}.csv"]);
        }
        return Inertia::render('Contable/Iva', ['libro' => $libro, 'periodo' => ['desde' => $desde, 'hasta' => $hasta], 'filas' => $filas, 'totales' => $tot, 'posicion' => $libro === 'ventas' ? null : null]);
    }

    private function filasIva(string $libro, string $desde, string $hasta): array
    {
        $q = Comprobante::with('contact:id,name,cuit,condicion_iva', 'impuestos')->where('estado', 'emitido')->where('direccion', $libro === 'ventas' ? 'venta' : 'compra')
            ->whereIn('tipo', array_keys(array_filter(Comprobante::TIPOS, fn($t) => $t['cc'] !== 0)))->whereBetween('fecha', [$desde, $hasta])->orderBy('fecha')->orderBy('id');
        return $q->get()->map(function ($c) {
            $s = $c->def()['cc'];
            $porAl = ['iva_21' => 0, 'iva_105' => 0, 'iva_27' => 0];
            foreach ($c->items as $it) {
                $al = (float) $it->alicuota_iva; $k = $al == 21 ? 'iva_21' : ($al == 10.5 ? 'iva_105' : ($al == 27 ? 'iva_27' : null));
                if ($k) $porAl[$k] += (float) $it->iva;
            }
            return ['id' => $c->id, 'fecha' => $c->fecha->format('d/m/Y'), 'tipo' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'contacto' => $c->contact?->name ?? 'Consumidor final', 'cuit' => $c->contact?->cuit ?? '', 'condicion' => $c->contact?->condicion_iva ?? '',
                'neto' => $s * ((float) $c->neto - (float) $c->descuento), 'iva' => $s * (float) $c->iva, 'iva_21' => $s * round($porAl['iva_21'], 2), 'iva_105' => $s * round($porAl['iva_105'], 2), 'iva_27' => $s * round($porAl['iva_27'], 2), 'exento' => $s * (float) $c->exento, 'percepciones' => $s * (float) $c->percepciones, 'total' => $s * (float) $c->total, 'nc' => $s < 0, 'direccion' => $c->direccion];
        })->all();
    }

    public function balance(Request $request, ContabilidadService $svc)
    {
        [$desde, $hasta] = $this->periodo($request);
        $b = $request->user()->business_id;
        $sumas = $svc->sumas($b, $desde, $hasta);
        $cuentas = CuentaContable::where('imputable', true)->orderBy('codigo')->get();
        $filas = $cuentas->map(function ($c) use ($sumas) {
            $s = $sumas[$c->id] ?? null; if (! $s) return null;
            $saldo = $s['debe'] - $s['haber'];
            return ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'debe' => $s['debe'], 'haber' => $s['haber'], 'deudor' => $saldo > 0 ? round($saldo, 2) : 0, 'acreedor' => $saldo < 0 ? round(-$saldo, 2) : 0];
        })->filter()->values();
        $res = $svc->resultado($b, $desde, $hasta);
        return Inertia::render('Contable/Balance', ['periodo' => ['desde' => $desde, 'hasta' => $hasta], 'filas' => $filas, 'totales' => ['debe' => round($filas->sum('debe'), 2), 'haber' => round($filas->sum('haber'), 2), 'deudor' => round($filas->sum('deudor'), 2), 'acreedor' => round($filas->sum('acreedor'), 2)], 'resultado' => $res, 'tipos' => CuentaContable::TIPOS]);
    }

    public function flujo(Request $request)
    {
        $meses = collect(range(5, 0))->map(fn($i) => now()->subMonths($i));
        $cats = ExpenseCategory::orderBy('name')->get();
        $filas = [];
        $add = function ($clave, $label, $mes, $monto) use (&$filas) { $filas[$clave] ??= ['label' => $label, 'meses' => [], 'total' => 0]; $filas[$clave]['meses'][$mes] = round(($filas[$clave]['meses'][$mes] ?? 0) + $monto, 2); $filas[$clave]['total'] = round($filas[$clave]['total'] + $monto, 2); };
        foreach ($meses as $m) {
            $k = $m->format('Y-m');
            $base = MovimientoFondos::whereYear('fecha', $m->year)->whereMonth('fecha', $m->month);
            $add('cobros', 'Cobros a clientes', $k, (float) (clone $base)->where('origen', 'cobro')->sum('ingreso'));
            $add('otros_ingresos', 'Otros ingresos', $k, (float) (clone $base)->whereIn('origen', ['ingreso', 'cheque'])->sum('ingreso'));
            $add('pagos', 'Pagos a proveedores', $k, -(float) (clone $base)->where('origen', 'pago')->sum('egreso'));
            foreach ($cats as $c) $add("cat_{$c->id}", "Gastos: {$c->name}", $k, -(float) (clone $base)->where('origen', 'gasto')->where('expense_category_id', $c->id)->sum('egreso'));
            $add('gastos_sin', 'Gastos sin categoría', $k, -(float) (clone $base)->where('origen', 'gasto')->whereNull('expense_category_id')->sum('egreso'));
            $add('cheques', 'Cheques propios debitados', $k, -(float) (clone $base)->where('origen', 'cheque')->sum('egreso'));
            $add('ajustes', 'Ajustes de caja', $k, (float) (clone $base)->whereIn('origen', ['ajuste', 'apertura'])->selectRaw('COALESCE(SUM(ingreso - egreso),0) s')->value('s'));
        }
        $filas = array_values(array_filter($filas, fn($f) => abs($f['total']) > 0.005));
        $neto = $meses->mapWithKeys(fn($m) => [$m->format('Y-m') => round(array_sum(array_map(fn($f) => $f['meses'][$m->format('Y-m')] ?? 0, $filas)), 2)]);
        return Inertia::render('Contable/Flujo', ['meses' => $meses->map(fn($m) => ['key' => $m->format('Y-m'), 'label' => $m->locale('es')->isoFormat('MMM YY')]), 'filas' => $filas, 'neto' => $neto, 'disponible' => (float) CuentaFondos::where('activa', true)->sum('saldo'),
            'proyeccion' => ['por_cobrar_30' => (float) Comprobante::ventas()->pendientesCobro()->whereBetween('fecha_vto', [today(), today()->addDays(30)])->sum('saldo'), 'por_pagar_30' => (float) Comprobante::compras()->pendientesPago()->whereBetween('fecha_vto', [today(), today()->addDays(30)])->sum('saldo'), 'cheques_cobrar' => (float) \App\Models\Cheque::enCartera()->sum('monto'), 'cheques_pagar' => (float) \App\Models\Cheque::propiosPendientes()->sum('monto'), 'vencido_cobrar' => (float) Comprobante::ventas()->pendientesCobro()->where('fecha_vto', '<', today()->toDateString())->sum('saldo'), 'vencido_pagar' => (float) Comprobante::compras()->pendientesPago()->where('fecha_vto', '<', today()->toDateString())->sum('saldo')]]);
    }
}
