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
        ];
    }
}
