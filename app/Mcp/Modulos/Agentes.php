<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Propuesta;
use App\Models\User;
use App\Services\Agentes\AgentesService;
use App\Services\Agentes\ControlInternoAgente;
use App\Services\Agentes\ResumenHabladoService;

// Fase 107: Bigcito trabaja de noche. Lo que dejaron los agentes, aprobarlo o rechazarlo por voz, el control interno y el resumen hablado.
class Agentes
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'propuestas_de_bigcito', 'titulo' => 'Qué me dejó Bigcito para aprobar', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Las propuestas que dejaron los agentes de Bigcito (cobrador, reposición, precios, cierre del mes, control interno) y esperan el visto bueno: a quién escribirle, qué pedir, qué precio subir, qué ajustar. Para aprobar o rechazar una, usá resolver_propuesta con su número.',
                'parametros' => ['agente' => ['type' => 'string', 'enum' => array_keys(AgentesService::AGENTES), 'description' => 'Sólo las de un agente']],
                'ejecutar' => function (User $u, array $a) {
                    $p = app(AgentesService::class)->pendientes($u->business)->when(! empty($a['agente']), fn($c) => $c->where('agente', $a['agente']));
                    return ['pendientes' => $p->count(), 'propuestas' => $p->map(fn($x) => ['numero' => $x->id, 'agente' => AgentesService::AGENTES[$x->agente]['label'] ?? $x->agente, 'titulo' => $x->titulo, 'detalle' => $x->detalle, 'importe' => $x->importe !== null ? (float) $x->importe : null, 'urgente' => $x->prioridad === 1, 'solo_aviso' => $x->soloAviso(), 'texto_propuesto' => $x->datos['texto'] ?? null])->values()->all(),
                        'agentes_activos' => app(AgentesService::class)->config($u->business)['activos'], 'url' => Busca::url('/propuestas')];
                }],
            ['nombre' => 'resolver_propuesta', 'titulo' => 'Aprobar o rechazar una propuesta de Bigcito', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Aprueba (ejecuta la acción: manda el recordatorio, crea la orden, cambia los precios, genera los asientos) o rechaza una propuesta de Bigcito por su número.',
                'parametros' => ['numero' => ['type' => 'integer'], 'decision' => ['type' => 'string', 'enum' => ['aprobar', 'rechazar']], 'motivo' => ['type' => 'string', 'description' => 'Si se rechaza, por qué (opcional)']], 'requeridos' => ['numero', 'decision'],
                'proponer' => function (User $u, array $a) {
                    $p = Propuesta::pendientes()->find((int) $a['numero']); if (! $p) return ['error' => "No hay una propuesta pendiente número {$a['numero']}."];
                    $ok = $a['decision'] === 'aprobar';
                    return ['titulo' => ($ok ? 'Aprobar: ' : 'Rechazar: ') . $p->titulo, 'detalle' => $ok ? ($p->soloAviso() ? 'Queda como vista.' : (string) $p->detalle) : 'Bigcito no insiste con lo mismo por una semana.', 'datos' => ['id' => $p->id, 'aprobar' => $ok, 'motivo' => $a['motivo'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    $p = app(AgentesService::class)->resolver(Propuesta::findOrFail($d['id']), $u, (bool) $d['aprobar'], $d['motivo'] ?? null);
                    return ['texto' => $d['aprobar'] ? ($p->resultado ?: 'Hecho.') : 'Rechazada.', 'url' => Busca::url('/propuestas')];
                }],
            ['nombre' => 'correr_agentes', 'titulo' => 'Que Bigcito revise ahora', 'modulo' => 'configuracion', 'permiso' => 'editar', 'tipo' => 'accion',
                'descripcion' => 'Corre ahora los agentes de Bigcito (o uno solo) en vez de esperar a la noche, y deja las propuestas nuevas.',
                'parametros' => ['agente' => ['type' => 'string', 'enum' => array_keys(AgentesService::AGENTES)]],
                'proponer' => fn(User $u, array $a) => ['titulo' => 'Revisar ahora' . (! empty($a['agente']) ? ': ' . AgentesService::AGENTES[$a['agente']]['label'] : ' con todos los agentes'), 'detalle' => 'Sólo deja propuestas; no cambia nada hasta que apruebes.', 'datos' => ['agente' => $a['agente'] ?? null]],
                'confirmar' => function (User $u, array $d) {
                    $r = app(AgentesService::class)->correr($u->business, $d['agente'] ? [$d['agente']] : null, true);
                    return ['texto' => array_sum($r) ? 'Listo: ' . array_sum($r) . ' propuesta(s) nueva(s).' : 'Revisé y no hay nada nuevo para proponer.', 'detalle' => $r, 'url' => Busca::url('/propuestas')];
                }],
            ['nombre' => 'control_interno', 'titulo' => 'Control interno del equipo', 'modulo' => 'estadisticas', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Compara a cada usuario con el resto en los últimos 30 días: anulaciones, descuentos, cajas con diferencia, ventas bajo lista, cargas a deshora. Dice quién se sale de lo normal y con qué evidencia. Sólo lo ve el dueño.',
                'parametros' => ['dias' => ['type' => 'integer']],
                'ejecutar' => function (User $u, array $a) {
                    if (! $u->esDueno()) return ['error' => 'El control interno lo ve sólo el dueño.'];
                    $r = app(ControlInternoAgente::class)->analizar($u->business, max(7, min(90, (int) ($a['dias'] ?? 30))));
                    return ['dias' => $r['dias'], 'se_salen_de_lo_normal' => $r['hallazgos'], 'por_usuario' => $r['usuarios'], 'nota' => $r['hallazgos'] ? 'Antes de hablar con la persona, mirá los comprobantes en Estadísticas → Qué hacer → Control de usuarios.' : 'Nadie se sale de lo normal.'];
                }],
            ['nombre' => 'resumen_hablado', 'titulo' => 'El resumen del día para escuchar', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'El parte de la mañana (cómo cerró ayer, qué vence hoy, lo raro) más las propuestas pendientes, redactado para leerse en voz alta. Leelo tal cual si el usuario te habló.',
                'parametros' => [],
                'ejecutar' => fn(User $u, array $a) => ['para_decir' => app(ResumenHabladoService::class)->texto($u->business)['hablado'], 'url' => Busca::url('/resumen-hablado')]],
        ];
    }
}
