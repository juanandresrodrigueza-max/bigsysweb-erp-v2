<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Cheque;
use App\Models\CuentaFondos;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Models\User;
use App\Services\Fondos\FondosService;

// Fondos: saldos, movimientos, cheques; gastos y transferencias entre cuentas.
class Fondos
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'saldos_cuentas', 'titulo' => 'Saldos de cajas y bancos', 'modulo' => 'fondos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Saldo actual de cada caja, banco y billetera, y el total disponible.',
                'ejecutar' => function (User $u, array $a) {
                    $c = CuentaFondos::where('activa', true)->orderBy('tipo')->get();
                    return ['cuentas' => $c->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombre, 'tipo' => $x->tipo, 'moneda' => $x->moneda, 'saldo' => (float) $x->saldo, 'bajo_minimo' => (float) $x->saldo_minimo > 0 && (float) $x->saldo < (float) $x->saldo_minimo])->all(),
                        'total_pesos' => round((float) $c->where('moneda', '!=', 'USD')->sum('saldo'), 2)];
                }],
            ['nombre' => 'movimientos_cuenta', 'titulo' => 'Movimientos de una cuenta', 'modulo' => 'fondos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Ingresos y egresos de una caja o banco en un período (hoy por defecto).',
                'parametros' => ['cuenta' => ['type' => 'string', 'description' => 'Nombre, id, "caja" o "banco".']] + Busca::PERIODO, 'requeridos' => ['cuenta'],
                'ejecutar' => function (User $u, array $a) {
                    $c = Busca::cuenta($a['cuenta'], null); if (! $c) return ['error' => 'No encuentro esa cuenta.'];
                    [$d, $h, $txt] = Busca::periodo($a, 'hoy');
                    $m = MovimientoFondos::where('cuenta_fondos_id', $c->id)->whereBetween('fecha', [$d->toDateString(), $h->toDateString()])->orderBy('fecha')->orderBy('id')->limit(200)->get();
                    return ['cuenta' => $c->nombre, 'periodo' => $txt, 'ingresos' => round((float) $m->sum('ingreso'), 2), 'egresos' => round((float) $m->sum('egreso'), 2), 'saldo_actual' => (float) $c->saldo,
                        'movimientos' => $m->map(fn($x) => ['fecha' => $x->fecha->toDateString(), 'concepto' => $x->concepto, 'ingreso' => (float) $x->ingreso, 'egreso' => (float) $x->egreso])->all()];
                }],
            ['nombre' => 'cheques_en_cartera', 'titulo' => 'Cheques en cartera', 'modulo' => 'fondos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Cheques de terceros en cartera, ordenados por fecha de cobro.',
                'ejecutar' => function (User $u, array $a) {
                    $c = Cheque::enCartera()->orderBy('fecha_pago')->limit(100)->get();
                    return ['cheques' => $c->map(fn($x) => ['numero' => $x->numero, 'banco' => $x->banco, 'emisor' => $x->emisor, 'cobro' => $x->fecha_pago?->toDateString(), 'monto' => (float) $x->monto, 'echeq' => (bool) $x->echeq])->all(), 'total' => round((float) $c->sum('monto'), 2)];
                }],
            ['nombre' => 'registrar_gasto', 'titulo' => 'Registrar un gasto', 'modulo' => 'fondos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Registra un gasto pagado desde una caja o banco (luz, alquiler, flete…), con su categoría.',
                'parametros' => ['concepto' => ['type' => 'string'], 'monto' => ['type' => 'number'], 'cuenta' => ['type' => 'string'], 'categoria' => ['type' => 'string'], 'fecha' => ['type' => 'string']],
                'requeridos' => ['concepto', 'monto'],
                'proponer' => function (User $u, array $a) {
                    $monto = round((float) $a['monto'], 2); if ($monto <= 0) return ['error' => '¿De cuánto es el gasto?'];
                    $concepto = trim((string) $a['concepto']) ?: 'Gasto';
                    $c = in_array($a['cuenta'] ?? 'caja', ['caja', 'banco', ''], true)
                        ? (CuentaFondos::where('activa', true)->where('tipo', ($a['cuenta'] ?? 'caja') === 'banco' ? 'banco' : 'caja')->where(fn($w) => $w->where('business_location_id', $u->current_location_id)->orWhereNull('business_location_id'))->orderByDesc('es_default')->first() ?? Busca::cuenta($a['cuenta'] ?? 'caja'))
                        : Busca::cuenta($a['cuenta'], null);
                    if (! $c) return ['error' => 'No encuentro la cuenta de donde sale el gasto.'];
                    $like = \App\Support\Sql::like();
                    $cat = ! empty($a['categoria']) ? ExpenseCategory::where('name', $like, "%{$a['categoria']}%")->first() : null;
                    $cat ??= ExpenseCategory::where('name', $like, '%' . explode(' ', $concepto)[0] . '%')->first();
                    if (! $cat && ($s = app(\App\Services\Contabilidad\ClasificadorGastosService::class)->sugerir($u->business, $concepto))) $cat = ExpenseCategory::find($s['expense_category_id']); // Fase 58
                    return ['titulo' => 'Gasto de ' . Busca::pesos($monto) . ": {$concepto}", 'detalle' => "Sale de {$c->nombre}" . ($cat ? " · categoría {$cat->name}" : ' · sin categoría') . '.',
                        'datos' => ['cuenta_id' => $c->id, 'concepto' => $concepto, 'egreso' => $monto, 'expense_category_id' => $cat?->id, 'fecha' => $a['fecha'] ?? today()->toDateString()]];
                },
                'confirmar' => function (User $u, array $d) {
                    $c = CuentaFondos::findOrFail($d['cuenta_id']);
                    app(FondosService::class)->registrar($c, ['origen' => 'gasto', 'concepto' => $d['concepto'], 'egreso' => $d['egreso'], 'expense_category_id' => $d['expense_category_id'], 'fecha' => $d['fecha']]);
                    return ['texto' => 'Listo: gasto de ' . Busca::pesos($d['egreso']) . " por {$d['concepto']} desde {$c->nombre}. Saldo " . Busca::pesos($c->fresh()->saldo) . '.', 'url' => Busca::url("/fondos?cuenta={$c->id}")];
                }],
            ['nombre' => 'transferir_entre_cuentas', 'titulo' => 'Transferir entre cuentas', 'modulo' => 'fondos', 'permiso' => 'crear', 'tipo' => 'accion', 'delicada' => true,
                'descripcion' => 'Pasa plata de una caja o banco a otro (por ejemplo, depositar el efectivo en el banco).',
                'parametros' => ['desde' => ['type' => 'string'], 'hasta' => ['type' => 'string'], 'monto' => ['type' => 'number']],
                'requeridos' => ['desde', 'hasta', 'monto'],
                'proponer' => function (User $u, array $a) {
                    $d = Busca::cuenta($a['desde'], null); $h = Busca::cuenta($a['hasta'], null);
                    if (! $d || ! $h) return ['error' => 'No encuentro alguna de las cuentas.'];
                    if ($d->id === $h->id) return ['error' => 'Elegí dos cuentas distintas.'];
                    return ['titulo' => 'Transferir ' . Busca::pesos($a['monto']) . " de {$d->nombre} a {$h->nombre}", 'detalle' => "Saldo actual de {$d->nombre}: " . Busca::pesos($d->saldo), 'datos' => ['desde' => $d->id, 'hasta' => $h->id, 'monto' => round((float) $a['monto'], 2)]];
                },
                'confirmar' => function (User $u, array $d) {
                    app(FondosService::class)->transferir(CuentaFondos::findOrFail($d['desde']), CuentaFondos::findOrFail($d['hasta']), $d['monto'], null, 'IA');
                    return ['texto' => 'Transferencia registrada.'];
                }],
            // Fase 99: traer los movimientos de Mercado Pago y ver qué quedó sin conciliar.
            ['nombre' => 'traer_mercadopago', 'titulo' => 'Traer los movimientos de Mercado Pago', 'modulo' => 'contable', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Trae de la API de Mercado Pago los cobros, comisiones y devoluciones a la cuenta de billetera y los concilia solos (con las reglas). Necesita Mercado Pago conectado y una cuenta de billetera marcada como la de Mercado Pago.',
                'parametros' => ['desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD; sin fecha, desde la última vez']],
                'proponer' => function (User $u, array $a) {
                    $svc = app(\App\Services\MercadoPago\MercadoPagoMovimientosService::class);
                    if (! $svc->conectado($u->business)) return ['error' => 'Mercado Pago no está conectado: Configuración → Mercado Pago → Conectar.'];
                    $c = $svc->cuenta($u->business); if (! $c) return ['error' => 'Marcá primero qué cuenta es la de Mercado Pago: Contable → Conciliación, elegí la billetera y «Esta es la cuenta de Mercado Pago».'];
                    return ['titulo' => "Traer los movimientos de Mercado Pago a {$c->nombre}", 'detalle' => ! empty($a['desde']) ? "Desde el {$a['desde']}." : 'Desde la última vez que se trajeron.', 'datos' => ['cuenta_id' => $c->id, 'desde' => $a['desde'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $r = app(\App\Services\MercadoPago\MercadoPagoMovimientosService::class)->traer($u->business, \App\Models\CuentaFondos::findOrFail($d['cuenta_id']), ! empty($d['desde']) ? \Carbon\Carbon::parse($d['desde']) : null);
                    return ['texto' => $r['nuevos'] ? "{$r['nuevos']} movimientos nuevos, {$r['conciliados']} resueltos solos." : 'Sin movimientos nuevos.'] + $r + ['url' => Busca::url('/contable/conciliacion?cuenta=' . $d['cuenta_id'])];
                }],
            ['nombre' => 'conciliacion_pendiente', 'titulo' => 'Qué quedó sin conciliar', 'modulo' => 'contable', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Por cuenta: cuántos renglones del banco o de Mercado Pago están sin conciliar y cuántos movimientos del sistema no aparecen en el extracto, con los importes. También las reglas que actúan solas.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $cuentas = \App\Models\CuentaFondos::whereIn('tipo', ['banco', 'billetera'])->where('activa', true)->get()->map(fn($c) => ['cuenta' => $c->nombre, 'tipo' => $c->tipo, 'extracto_pendiente' => \App\Models\ExtractoItem::where('cuenta_fondos_id', $c->id)->where('estado', 'pendiente')->count(), 'monto_extracto_pendiente' => round((float) \App\Models\ExtractoItem::where('cuenta_fondos_id', $c->id)->where('estado', 'pendiente')->sum('monto'), 2), 'sistema_sin_conciliar' => \App\Models\MovimientoFondos::where('cuenta_fondos_id', $c->id)->where('conciliado', false)->count(), 'url' => Busca::url('/contable/conciliacion?cuenta=' . $c->id)])->all();
                    return ['cuentas' => $cuentas, 'reglas' => app(\App\Services\Contabilidad\ReglasConciliacionService::class)->listado($u->business)];
                }],
        ];
    }
}
