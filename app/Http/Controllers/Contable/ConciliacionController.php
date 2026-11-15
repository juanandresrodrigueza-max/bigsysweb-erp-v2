<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\ExtractoBancario;
use App\Models\ExtractoItem;
use App\Models\MovimientoFondos;
use App\Services\Contabilidad\ConciliacionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConciliacionController extends Controller
{
    public function index(Request $request)
    {
        $bancos = CuentaFondos::whereIn('tipo', ['banco', 'billetera'])->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo', 'saldo', 'banco']);
        $cuentaId = (int) ($request->cuenta ?: $bancos->first()?->id);
        $estado = $request->estado ?? 'pendiente';
        $items = ExtractoItem::with('movimiento:id,fecha,concepto,ingreso,egreso,referencia')->where('cuenta_fondos_id', $cuentaId)
            ->when($estado !== 'todos', fn($q) => $q->where('estado', $estado))->orderByDesc('fecha')->orderByDesc('id')->limit(300)->get()
            ->map(fn($i) => ['id' => $i->id, 'fecha' => $i->fecha->format('d/m/Y'), 'descripcion' => $i->descripcion, 'referencia' => $i->referencia, 'monto' => (float) $i->monto, 'saldo' => $i->saldo !== null ? (float) $i->saldo : null, 'estado' => $i->estado, 'match' => $i->match, 'movimiento' => $i->movimiento ? ['id' => $i->movimiento->id, 'fecha' => $i->movimiento->fecha->format('d/m/Y'), 'concepto' => $i->movimiento->concepto] : null]);
        $sinConciliar = MovimientoFondos::where('cuenta_fondos_id', $cuentaId)->where('conciliado', false)->orderByDesc('fecha')->orderByDesc('id')->limit(200)->get()
            ->map(fn($m) => ['id' => $m->id, 'fecha' => $m->fecha->format('d/m/Y'), 'concepto' => $m->concepto, 'referencia' => $m->referencia, 'monto' => (float) $m->ingreso - (float) $m->egreso, 'origen' => $m->origen]);
        $cuenta = $bancos->firstWhere('id', $cuentaId);
        $ultimoExt = ExtractoBancario::where('cuenta_fondos_id', $cuentaId)->latest('id')->first();

        return Inertia::render('Contable/Conciliacion', [
            'bancos' => $bancos, 'cuentaId' => $cuentaId, 'estado' => $estado, 'items' => $items, 'sinConciliar' => $sinConciliar,
            'resumen' => [
                'saldo_sistema' => (float) ($cuenta?->saldo ?? 0), 'saldo_extracto' => $ultimoExt?->saldo_final !== null ? (float) $ultimoExt->saldo_final : null,
                'pendientes_extracto' => ExtractoItem::where('cuenta_fondos_id', $cuentaId)->where('estado', 'pendiente')->count(), 'pendientes_sistema' => $sinConciliar->count(),
                'monto_pend_extracto' => (float) ExtractoItem::where('cuenta_fondos_id', $cuentaId)->where('estado', 'pendiente')->sum('monto'), 'monto_pend_sistema' => round($sinConciliar->sum('monto'), 2),
                'ultimo_extracto' => $ultimoExt ? ['fecha' => $ultimoExt->created_at->format('d/m/Y'), 'desde' => $ultimoExt->desde?->format('d/m'), 'hasta' => $ultimoExt->hasta?->format('d/m'), 'items' => $ultimoExt->items, 'archivo' => $ultimoExt->archivo] : null,
            ],
            'categorias' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'cajones' => $cuenta ? app(\App\Services\Contabilidad\BancoCajonesService::class)->cajones(CuentaFondos::find($cuentaId)) : null,
            'clases' => \App\Services\Contabilidad\BancoCajonesService::CLASES,
            // Transferencias que entraron: de qué cliente son (por CUIT, DNI o nombre exacto) para hacer el recibo con un clic.
            'deClientes' => $cuenta ? app(\App\Services\Comprobantes\TransferenciaClienteService::class)->sugerencias(CuentaFondos::find($cuentaId)) : [],
            'clientes' => \App\Models\Contact::customers()->where('is_active', true)->where('name', '!=', 'Consumidor Final')->orderBy('name')->limit(3000)->get(['id', 'name'])->map(fn($c) => ['id' => $c->id, 'nombre' => $c->name]),
            // Fase 99: reglas que actúan solas y la cuenta de Mercado Pago que se trae por API
            'reglas' => app(\App\Services\Contabilidad\ReglasConciliacionService::class)->listado($request->user()->business), 'acciones' => \App\Models\ConciliacionRegla::ACCIONES,
            'mercadopago' => $this->mp($request->user()->business, $cuenta ? CuentaFondos::find($cuentaId) : null),
        ]);
    }

    private function mp(\App\Models\Business $b, ?CuentaFondos $cuenta): array
    {
        $svc = app(\App\Services\MercadoPago\MercadoPagoMovimientosService::class); $cfg = (array) ($cuenta?->conciliacion_config ?? []);
        return ['conectado' => $svc->conectado($b), 'es_billetera' => $cuenta?->tipo === 'billetera', 'activo' => (bool) ($cfg['mercadopago'] ?? false), 'automatico' => (bool) ($cfg['mp_automatico'] ?? false), 'ultima' => $cfg['mp_ultima'] ?? null, 'otra_cuenta' => ($c = $svc->cuenta($b)) && $cuenta && $c->id !== $cuenta->id ? $c->nombre : null];
    }

    // Fase 99: traer los movimientos de Mercado Pago a la cuenta de billetera (botón) y marcar la cuenta para que se traigan solos.
    public function mercadopago(Request $request, \App\Services\MercadoPago\MercadoPagoMovimientosService $svc)
    {
        $d = $request->validate(['cuenta' => 'required|integer', 'desde' => 'nullable|date']);
        $c = CuentaFondos::findOrFail($d['cuenta']); abort_unless($c->tipo === 'billetera', 422, 'Elegí la cuenta de Mercado Pago (billetera virtual).');
        if (empty(($c->conciliacion_config ?? [])['mercadopago'])) $svc->configurar($c, true, true);
        $r = $svc->traer($request->user()->business, $c, ! empty($d['desde']) ? \Carbon\Carbon::parse($d['desde']) : null);
        return back()->with('success', $r['nuevos'] ? "Mercado Pago: {$r['nuevos']} movimientos nuevos del {$r['desde']} al {$r['hasta']}, {$r['conciliados']} resueltos solos." : "Mercado Pago: sin movimientos nuevos del {$r['desde']} al {$r['hasta']}.");
    }

    public function mercadopagoConfig(Request $request, \App\Services\MercadoPago\MercadoPagoMovimientosService $svc)
    {
        $d = $request->validate(['cuenta' => 'required|integer', 'activo' => 'required|boolean', 'automatico' => 'boolean']);
        $svc->configurar(CuentaFondos::findOrFail($d['cuenta']), $d['activo'], $request->boolean('automatico', true));
        return back()->with('success', $d['activo'] ? 'Listo: esta cuenta es la de Mercado Pago' . ($request->boolean('automatico', true) ? ' y los movimientos se traen solos cada madrugada.' : '.') : 'Esta cuenta ya no se trae de Mercado Pago.');
    }

    // Fase 99: reglas
    public function reglaGuardar(Request $request, \App\Services\Contabilidad\ReglasConciliacionService $svc)
    {
        $d = $request->validate(['patron' => 'required|string|max:160', 'sentido' => 'nullable|in:ingreso,egreso,ambos', 'accion' => 'required|in:gasto,ignorar,recibo', 'expense_category_id' => 'nullable|integer', 'contact_id' => 'nullable|integer', 'concepto' => 'nullable|string|max:150', 'cuenta_fondos_id' => 'nullable|integer', 'aplicar' => 'nullable|integer']);
        $svc->crear($request->user()->business, $d, $request->user()->id);
        $n = ! empty($d['aplicar']) ? $svc->aplicar(CuentaFondos::findOrFail($d['aplicar'])) : 0;
        return back()->with('success', 'Regla guardada.' . ($n ? " Resolvió {$n} renglones pendientes." : ''));
    }

    public function reglaBorrar(int $id)
    {
        \App\Models\ConciliacionRegla::findOrFail($id)->delete();
        return back()->with('success', 'Regla borrada.');
    }

    public function reglasAplicar(Request $request, \App\Services\Contabilidad\ReglasConciliacionService $svc)
    {
        $n = $svc->aplicar(CuentaFondos::findOrFail($request->integer('cuenta')));
        return back()->with('success', $n ? "Las reglas resolvieron {$n} renglones." : 'Ningún renglón pendiente coincide con una regla.');
    }

    // Fase 47: una transferencia del extracto se vuelve recibo del cliente y queda conciliada.
    public function recibo(Request $request, int $id, \App\Services\Comprobantes\TransferenciaClienteService $t)
    {
        abort_unless($request->user()->puede('clientes', 'crear'), 403, 'Tu rol no puede cargar cobros.');
        $d = $request->validate(['contact_id' => 'required|integer', 'recordar' => 'boolean']);
        $it = ExtractoItem::findOrFail($id);
        $cobro = $t->reciboDesdeExtracto($it, \App\Models\Contact::customers()->findOrFail($d['contact_id']));
        $regla = $request->boolean('recordar') ? app(\App\Services\Contabilidad\ReglasConciliacionService::class)->recordarDesde($it, 'recibo', ['contact_id' => $cobro->contact_id], $request->user()->id) : null; // Fase 99
        return back()->with('success', "Recibo {$cobro->numeroFormateado()} hecho a {$cobro->contact->name} y conciliado con el banco." . ($regla ? " Las próximas transferencias con «{$regla->patron}» se vuelven recibo solas." : ''));
    }

    public function importar(Request $request, ConciliacionService $svc)
    {
        $d = $request->validate(['cuenta_fondos_id' => 'required|exists:cuentas_fondos,id', 'archivo' => 'required|file|max:15360|mimes:pdf,xlsx,xls,csv,txt']);
        $ext = $svc->importarArchivo(CuentaFondos::findOrFail($d['cuenta_fondos_id']), $request->file('archivo')->getRealPath(), $request->file('archivo')->getClientOriginalName());
        $conc = ExtractoItem::where('extracto_id', $ext->id)->where('estado', 'conciliado')->count();
        return back()->with('success', "Importados {$ext->items} renglones; {$conc} conciliados automáticamente.");
    }

    public function automatica(Request $request, ConciliacionService $svc)
    {
        $n = $svc->conciliarAutomatico(CuentaFondos::findOrFail($request->integer('cuenta')));
        return back()->with('success', "{$n} renglones conciliados.");
    }

    public function vincular(Request $request, int $id, ConciliacionService $svc)
    {
        $d = $request->validate(['movimiento_id' => 'required|exists:movimientos_fondos,id']);
        $svc->vincular(ExtractoItem::findOrFail($id), MovimientoFondos::findOrFail($d['movimiento_id']));
        return back()->with('success', 'Conciliado.');
    }

    public function desvincular(int $id, ConciliacionService $svc)
    {
        $svc->desvincular(ExtractoItem::findOrFail($id));
        return back()->with('success', 'Se deshizo la conciliación.');
    }

    public function ignorar(Request $request, int $id)
    {
        $it = ExtractoItem::findOrFail($id);
        $it->update(['estado' => $it->estado === 'ignorado' ? 'pendiente' : 'ignorado']);
        if ($it->estado === 'ignorado' && $request->boolean('recordar')) { $r = app(\App\Services\Contabilidad\ReglasConciliacionService::class)->recordarDesde($it, 'ignorar', [], $request->user()->id); return back()->with('success', $r ? "Listo: «{$r->patron}» se ignora solo de ahora en más." : 'Ignorado.'); } // Fase 99
        return back();
    }

    public function registrar(Request $request, int $id, ConciliacionService $svc)
    {
        $d = $request->validate(['expense_category_id' => 'nullable|exists:expense_categories,id', 'concepto' => 'nullable|string|max:150', 'recordar' => 'boolean']);
        $it = ExtractoItem::findOrFail($id);
        $m = $svc->registrarDesdeExtracto($it, $d['expense_category_id'] ?? null, $d['concepto'] ?? null);
        $regla = $request->boolean('recordar') ? app(\App\Services\Contabilidad\ReglasConciliacionService::class)->recordarDesde($it, 'gasto', ['expense_category_id' => $m->expense_category_id, 'concepto' => $d['concepto'] ?? null], $request->user()->id) : null; // Fase 99
        return back()->with('success', "Registrado en Fondos: {$m->concepto}." . ($regla ? " De ahora en más «{$regla->patron}» se registra solo." : ''));
    }

    // Fase 35.3: clasificar un concepto una vez (queda para todos los extractos) y registrar de una vez un grupo de gastos.
    public function clasificar(Request $request, int $id)
    {
        $d = $request->validate(['clase' => 'required|string']);
        app(\App\Services\Contabilidad\BancoCajonesService::class)->aprender(ExtractoItem::findOrFail($id), $d['clase']);
        return back()->with('success', 'Listo: ese concepto queda clasificado para todos tus extractos.');
    }

    public function registrarGrupo(Request $request)
    {
        $d = $request->validate(['cuenta' => 'required|integer', 'clase' => 'required|string', 'expense_category_id' => 'nullable|integer']);
        $n = app(\App\Services\Contabilidad\BancoCajonesService::class)->registrarGrupo(CuentaFondos::findOrFail($d['cuenta']), $d['clase'], $d['expense_category_id'] ?? null);
        return back()->with('success', "Registrados {$n} renglones del banco en Fondos, ya conciliados.");
    }

    // Fase 58: la IA clasifica de una vez los débitos pendientes que no tienen categoría (y queda aprendido).
    public function clasificarIa(Request $request)
    {
        $d = $request->validate(['cuenta' => 'required|integer']);
        $cuenta = CuentaFondos::findOrFail($d['cuenta']); $b = $request->user()->business;
        abort_unless(\App\Support\Ia::tiene($b), 422, 'Cargá la clave de IA en Configuración → IA.');
        $clas = app(\App\Services\Contabilidad\ClasificadorGastosService::class);
        $pend = ExtractoItem::where('cuenta_fondos_id', $cuenta->id)->where('estado', 'pendiente')->where('monto', '<', 0)->get()->filter(fn($it) => ! $clas->sugerir($b, $it->descripcion, false))->values();
        if ($pend->isEmpty()) return back()->with('success', 'Todos los débitos pendientes ya tienen categoría.');
        $r = $clas->sugerirLote($b, $pend->pluck('descripcion')->all());
        return back()->with('success', count($r) . ' de ' . $pend->count() . ' renglones clasificados con IA. Quedan aprendidos para los próximos extractos.');
    }
}
