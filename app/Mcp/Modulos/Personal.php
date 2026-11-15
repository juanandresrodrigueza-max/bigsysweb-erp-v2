<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Liquidacion;
use App\Models\OrdenTrabajo;
use App\Models\User;
use App\Services\Servicios\OrdenesService;

// Personal y servicios: liquidaciones de sueldos, órdenes de servicio técnico y alta de órdenes.
class Personal
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'liquidaciones_sueldos', 'titulo' => 'Liquidaciones de sueldos', 'modulo' => 'sueldos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Últimas liquidaciones de sueldos con bruto, neto, contribuciones y estado.',
                'parametros' => ['cantidad' => ['type' => 'integer']],
                'ejecutar' => fn(User $u, array $a) => ['liquidaciones' => Liquidacion::orderByDesc('periodo')->orderByDesc('id')->limit(min(24, (int) ($a['cantidad'] ?? 6)))->get()
                    ->map(fn($l) => ['periodo' => $l->periodo, 'tipo' => $l->tipo, 'estado' => $l->estado, 'bruto' => (float) $l->total_bruto, 'neto' => (float) $l->total_neto, 'contribuciones' => (float) $l->total_contribuciones, 'pagada' => (bool) $l->pagada_en])->all()]],
            // Fase 65: liquidar con las novedades contadas en castellano, y el recibo explicado para mandárselo al empleado.
            ['nombre' => 'liquidar_sueldos', 'titulo' => 'Liquidar sueldos', 'modulo' => 'sueldos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Calcula la liquidación de sueldos de un período en borrador a partir de las novedades del mes contadas en castellano (horas extra, faltas, vacaciones, feriados, premios, anticipos, quién no se liquida). Los empleados que no se nombran van con 30 días y sin extras.',
                'parametros' => ['periodo' => ['type' => 'string', 'description' => 'YYYY-MM'], 'tipo' => ['type' => 'string', 'description' => 'mensual (por defecto), sac o final'], 'novedades' => ['type' => 'string', 'description' => 'Las novedades tal cual las dice el dueño.']],
                'requeridos' => ['periodo'],
                'proponer' => function (User $u, array $a) {
                    if (! preg_match('/^\d{4}-\d{2}$/', (string) $a['periodo'])) return ['error' => 'El período va como YYYY-MM, por ejemplo 2026-10.'];
                    $tipo = in_array($a['tipo'] ?? 'mensual', ['mensual', 'sac', 'final'], true) ? ($a['tipo'] ?? 'mensual') : 'mensual';
                    $r = trim((string) ($a['novedades'] ?? '')) !== '' ? app(\App\Services\Sueldos\NovedadesTextoService::class)->interpretar($u->business, (string) $a['novedades']) : ['novedades' => [], 'filas' => [], 'no_entendido' => []];
                    $n = \App\Models\Empleado::where('activo', true)->count(); if (! $n) return ['error' => 'No hay empleados activos cargados en Sueldos.'];
                    $lineas = collect($r['filas'])->map(fn($f) => "{$f['empleado']}: {$f['dias']} días" . ($f['feriados'] ? ", {$f['feriados']} feriado(s)" : '') . ($f['vacaciones'] ? ", {$f['vacaciones']} vac." : '') . ($f['horas_extra_50'] ? ", {$f['horas_extra_50']} hs al 50" : '') . ($f['horas_extra_100'] ? ", {$f['horas_extra_100']} hs al 100" : '') . ($f['adicionales'] ? ', premio ' . Busca::pesos($f['adicionales']) : '') . ($f['no_rem_extra'] ? ', no rem ' . Busca::pesos($f['no_rem_extra']) : '') . ($f['anticipos'] ? ', anticipo ' . Busca::pesos($f['anticipos']) : '') . ($f['excluir'] ? ' · NO SE LIQUIDA' : '') . ($f['nota'] ? " ({$f['nota']})" : ''))->implode("\n");
                    return ['titulo' => "Liquidar sueldos {$a['periodo']} ({$tipo}) en borrador", 'detalle' => ($lineas ?: 'Sin novedades: todos con 30 días.') . "\nLos otros " . max(0, $n - count($r['filas'])) . ' empleado(s) van con 30 días y sin extras.' . ($r['no_entendido'] ? "\nNo entendí: " . implode(' | ', $r['no_entendido']) : ''),
                        'datos' => ['periodo' => $a['periodo'], 'tipo' => $tipo, 'novedades' => $r['novedades']]];
                },
                'confirmar' => function (User $u, array $d) {
                    $liq = app(\App\Services\Sueldos\SueldosService::class)->liquidar($u->business, $d['periodo'], $d['tipo'], $d['novedades']);
                    return ['texto' => "Liquidación {$liq->periodoLabel()} calculada en borrador: {$liq->items()->count()} recibos, neto " . Busca::pesos((float) $liq->total_neto) . '. Revisala y confirmala en Sueldos.', 'liquidacion_id' => $liq->id, 'url' => Busca::url("/sueldos?periodo={$liq->periodo}&tipo={$liq->tipo}")];
                }],
            ['nombre' => 'explicar_recibo', 'titulo' => 'Explicar un recibo', 'modulo' => 'sueldos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'El recibo de sueldo de un empleado explicado en castellano llano (qué ganó, qué le descontaron y para qué, cuánto cobra), para mandárselo o leérselo.',
                'parametros' => ['empleado' => ['type' => 'string'], 'periodo' => ['type' => 'string', 'description' => 'YYYY-MM; si falta, el último']], 'requeridos' => ['empleado'],
                'ejecutar' => function (User $u, array $a) {
                    $like = \App\Support\Sql::like(); $t = trim((string) $a['empleado']);
                    $e = \App\Models\Empleado::where(fn($q) => $q->where('nombre', $like, "%{$t}%")->when(ctype_digit($t), fn($w) => $w->orWhere('legajo', (int) $t)))->first(); // el legajo es numérico: en PostgreSQL no se compara con texto if (! $e) return ['error' => "No encuentro al empleado \"{$t}\"."];
                    $item = \App\Models\LiquidacionItem::where('empleado_id', $e->id)->whereHas('liquidacion', fn($q) => $q->when(! empty($a['periodo']), fn($w) => $w->where('periodo', $a['periodo'])))->with('liquidacion', 'empleado')->orderByDesc('id')->first();
                    if (! $item) return ['error' => "{$e->nombre} no tiene recibo" . (! empty($a['periodo']) ? " de {$a['periodo']}" : '') . '.'];
                    $x = app(\App\Services\Sueldos\NovedadesTextoService::class)->explicarRecibo($u->business, $item);
                    return ['empleado' => $e->nombre, 'periodo' => $item->liquidacion?->periodo, 'neto' => (float) $item->neto, 'explicacion' => $x['texto'], 'link_recibo' => $item->token ? Busca::url("/recibo/{$item->token}") : null];
                }],
            ['nombre' => 'ordenes_servicio', 'titulo' => 'Órdenes de servicio', 'modulo' => 'servicios', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Órdenes de servicio técnico abiertas (o de un cliente), con estado, equipo y fecha prometida.',
                'parametros' => ['cliente' => ['type' => 'string'], 'incluir_entregadas' => ['type' => 'boolean']],
                'ejecutar' => function (User $u, array $a) {
                    $c = ! empty($a['cliente']) ? Busca::cliente($a['cliente']) : null;
                    $q = OrdenTrabajo::query()->when($c, fn($q) => $q->where('contact_id', $c->id))->when(empty($a['incluir_entregadas']), fn($q) => $q->whereNotIn('estado', ['entregado', 'cancelado']));
                    return ['ordenes' => $q->orderBy('fecha_prometida')->limit(60)->get()->map(fn($o) => ['numero' => $o->numeroFormateado(), 'cliente' => $o->clienteNombre(), 'equipo' => trim("{$o->equipo} {$o->marca_modelo}"), 'falla' => $o->falla, 'estado' => $o->estado, 'prometida' => $o->fecha_prometida?->toDateString(), 'presupuesto' => (float) $o->presupuesto, 'url' => Busca::url("/servicios/{$o->id}")])->all()];
                }],
            ['nombre' => 'crear_orden_servicio', 'titulo' => 'Crear orden de servicio', 'modulo' => 'servicios', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Ingresa un equipo a servicio técnico para un cliente.',
                'parametros' => ['cliente' => ['type' => 'string'], 'equipo' => ['type' => 'string'], 'falla' => ['type' => 'string'], 'marca_modelo' => ['type' => 'string'], 'serie' => ['type' => 'string'], 'telefono' => ['type' => 'string']],
                'requeridos' => ['cliente', 'equipo', 'falla'],
                'proponer' => function (User $u, array $a) {
                    $c = Busca::cliente($a['cliente']);
                    return ['titulo' => "Orden de servicio: {$a['equipo']} de " . ($c?->name ?? $a['cliente']), 'detalle' => "Falla: {$a['falla']}" . ($c ? '' : ' · (cliente no registrado, se guarda el nombre)'),
                        'datos' => ['contact_id' => $c?->id, 'nombre' => $c ? null : $a['cliente'], 'telefono' => $a['telefono'] ?? $c?->phone, 'equipo' => $a['equipo'], 'falla' => $a['falla'], 'marca_modelo' => $a['marca_modelo'] ?? null, 'serie' => $a['serie'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $ot = app(OrdenesService::class)->crear(array_filter($d, fn($v) => $v !== null));
                    return ['texto' => "Orden {$ot->numeroFormateado()} creada.", 'orden_id' => $ot->id, 'url' => Busca::url("/servicios/{$ot->id}")];
                }],
            // Fase 98: ausencias, vacaciones (LCT), feriados y el Libro de Sueldos Digital.
            ['nombre' => 'vacaciones_empleado', 'titulo' => 'Vacaciones que le quedan a un empleado', 'modulo' => 'sueldos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Días de vacaciones que le corresponden por antigüedad (LCT), los que ya tomó y el saldo. Sin empleado, el tablero de todos.',
                'parametros' => ['empleado' => ['type' => 'string', 'description' => 'Nombre o legajo'], 'anio' => ['type' => 'integer']],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(\App\Services\Personal\AusenciasService::class); $anio = (int) ($a['anio'] ?? today()->year);
                    if (! empty($a['empleado'])) { $e = self::empleado($a['empleado']); if (! $e) return ['error' => "No encuentro al empleado {$a['empleado']}."]; return ['empleado' => $e->nombre, 'legajo' => $e->legajo, 'ingreso' => $e->fecha_ingreso->format('d/m/Y')] + $svc->saldoVacaciones($e, $anio) + ['url' => Busca::url('/sueldos/personal?anio=' . $anio)]; }
                    return ['anio' => $anio, 'empleados' => $svc->tableroVacaciones($u->business, $anio), 'url' => Busca::url('/sueldos/personal?anio=' . $anio)];
                }],
            ['nombre' => 'ausencias', 'titulo' => 'Quién falta hoy, licencias del mes y próximas vacaciones', 'modulo' => 'sueldos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Quién está ausente hoy (vacaciones, enfermedad, licencia), las ausencias de un mes y las vacaciones que arrancan en los próximos 30 días. También las pendientes de aprobar.',
                'parametros' => ['mes' => ['type' => 'string', 'description' => 'YYYY-MM; sin mes, el actual']],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(\App\Services\Personal\AusenciasService::class); $mes = preg_match('/^\d{4}-\d{2}$/', (string) ($a['mes'] ?? '')) ? $a['mes'] : today()->format('Y-m');
                    return ['hoy' => $svc->hoy($u->business), 'mes' => $mes, 'del_mes' => $svc->delMes($u->business, $mes), 'proximas_vacaciones' => $svc->proximasVacaciones($u->business, 30),
                        'pendientes' => \App\Models\Ausencia::with('empleado:id,nombre,legajo')->where('estado', 'pendiente')->orderBy('desde')->get()->map(fn($x) => $svc->arr($x))->all(), 'url' => Busca::url('/sueldos/personal?mes=' . $mes)];
                }],
            ['nombre' => 'cargar_ausencia', 'titulo' => 'Cargar vacaciones, licencia o falta', 'modulo' => 'sueldos', 'permiso' => 'crear', 'tipo' => 'accion',
                'descripcion' => 'Carga una ausencia de un empleado: vacaciones, enfermedad, examen, matrimonio, nacimiento, fallecimiento, sin goce, falta injustificada, suspensión u otra. Cuenta los días sola y, al liquidar, ya aparece en las novedades del mes.',
                'parametros' => ['empleado' => ['type' => 'string'], 'tipo' => ['type' => 'string', 'enum' => array_keys(\App\Models\Ausencia::TIPOS)], 'desde' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'hasta' => ['type' => 'string', 'description' => 'YYYY-MM-DD; si falta, el mismo día'], 'notas' => ['type' => 'string'], 'certificado' => ['type' => 'boolean']],
                'requeridos' => ['empleado', 'tipo', 'desde'],
                'proponer' => function (User $u, array $a) {
                    $e = self::empleado($a['empleado']); if (! $e) return ['error' => "No encuentro al empleado {$a['empleado']}."];
                    if (! isset(\App\Models\Ausencia::TIPOS[$a['tipo']])) return ['error' => 'Tipo desconocido. Puede ser: ' . implode(', ', array_keys(\App\Models\Ausencia::TIPOS)) . '.'];
                    try { $desde = \Carbon\Carbon::parse($a['desde']); $hasta = \Carbon\Carbon::parse($a['hasta'] ?? $a['desde']); } catch (\Throwable) { return ['error' => 'Las fechas van como YYYY-MM-DD.']; }
                    if ($hasta->lt($desde)) return ['error' => 'La fecha "hasta" es anterior a "desde".'];
                    $svc = app(\App\Services\Personal\AusenciasService::class); $dias = $svc->contarDias($u->business, $a['tipo'], $desde, $hasta);
                    $det = "{$dias} días, del {$desde->format('d/m/Y')} al {$hasta->format('d/m/Y')}.";
                    if ($a['tipo'] === 'vacaciones') { $s = $svc->saldoVacaciones($e, $desde->month <= 4 ? $desde->year - 1 : $desde->year); $det .= " Le quedan {$s['saldo']} de {$s['derecho']} del {$s['anio']}" . ($s['saldo'] - $dias < 0 ? ' (se pasa ' . ($dias - $s['saldo']) . ')' : '') . '.'; }
                    return ['titulo' => \App\Models\Ausencia::TIPOS[$a['tipo']]['label'] . " de {$e->nombre}", 'detalle' => $det . ($a['notas'] ?? '' ? " {$a['notas']}" : ''), 'datos' => ['empleado_id' => $e->id, 'tipo' => $a['tipo'], 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'notas' => $a['notas'] ?? null, 'certificado' => (bool) ($a['certificado'] ?? false)]];
                },
                'confirmar' => function (User $u, array $d) {
                    $x = app(\App\Services\Personal\AusenciasService::class)->guardar($u->business, $u, $d);
                    return ['texto' => "{$x->tipoLabel()} de {$x->empleado->nombre} cargada: {$x->dias} días.", 'ausencia_id' => $x->id, 'url' => Busca::url('/sueldos/personal?mes=' . $x->desde->format('Y-m'))];
                }],
            ['nombre' => 'feriados', 'titulo' => 'Próximos feriados y los del año', 'modulo' => 'sueldos', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los próximos feriados (nacionales calculados más los propios de la empresa) y, con año, el calendario completo. También cuántos feriados tiene un mes para el recibo.',
                'parametros' => ['anio' => ['type' => 'integer'], 'mes' => ['type' => 'string', 'description' => 'YYYY-MM']],
                'ejecutar' => function (User $u, array $a) {
                    $f = app(\App\Services\Personal\FeriadosService::class); $r = ['proximos' => $f->proximos($u->business, 5)];
                    if (! empty($a['anio'])) $r['anio'] = collect($f->listado($u->business, (int) $a['anio']))->where('quitado', false)->values()->all();
                    if (! empty($a['mes']) && preg_match('/^\d{4}-\d{2}$/', $a['mes'])) $r['feriados_del_mes'] = $f->enMes($u->business, $a['mes']);
                    return $r + ['url' => Busca::url('/sueldos/personal')];
                }],
            ['nombre' => 'libro_sueldos_digital', 'titulo' => 'Libro de Sueldos Digital de un período', 'modulo' => 'sueldos', 'permiso' => 'exportar', 'tipo' => 'consulta',
                'descripcion' => 'Resumen y link de descarga del archivo del Libro de Sueldos Digital (ARCA) de una liquidación: empleados, conceptos, remuneración y qué falta (CUIL).',
                'parametros' => ['periodo' => ['type' => 'string', 'description' => 'YYYY-MM; sin período, la última'], 'tipo' => ['type' => 'string', 'enum' => ['mensual', 'sac', 'final']]],
                'ejecutar' => function (User $u, array $a) {
                    $liq = Liquidacion::when(! empty($a['periodo']), fn($q) => $q->where('periodo', $a['periodo']))->when(! empty($a['tipo']), fn($q) => $q->where('tipo', $a['tipo']))->orderByDesc('periodo')->orderByDesc('id')->first();
                    if (! $liq) return ['error' => 'No hay liquidación para ese período.'];
                    return app(\App\Services\Sueldos\LibroSueldosDigitalService::class)->resumen($liq) + ['estado' => $liq->estado, 'descarga' => Busca::url("/sueldos/{$liq->id}/lsd"), 'aviso' => $liq->estado === 'borrador' ? 'La liquidación está en borrador: confirmala antes de presentar el libro.' : null];
                }],
        ];
    }

    private static function empleado($texto): ?\App\Models\Empleado
    {
        $t = trim((string) $texto); if ($t === '') return null;
        $like = \App\Support\Sql::like();
        return (ctype_digit($t) ? \App\Models\Empleado::where('legajo', (int) $t)->first() : null)
            ?? \App\Models\Empleado::where('activo', true)->where('nombre', $like, "%{$t}%")->first()
            ?? \App\Models\Empleado::where('activo', true)->where(function ($w) use ($t, $like) { foreach (array_filter(explode(' ', $t), fn($p) => mb_strlen($p) > 2) as $p) $w->where('nombre', $like, "%{$p}%"); })->first();
    }
}
