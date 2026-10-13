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
