<?php

namespace App\Mcp\Modulos;

use App\Models\BigcitoRutina;
use App\Models\User;
use App\Services\IA\RutinasService;
use Illuminate\Validation\ValidationException;

// Fase 119.4: rutinas de Bigcito por voz. «Todos los lunes a las 8 pasame cómo cerró la semana», «avisame si alguien pasa su límite».
class Rutinas
{
    private static function buscar(User $u, $ref): ?BigcitoRutina
    {
        $t = trim((string) $ref); if ($t === '') return null;
        $q = fn() => BigcitoRutina::where('user_id', $u->id);
        if (ctype_digit($t) && ($r = $q()->find((int) $t))) return $r;
        return $q()->where('nombre', \App\Support\Sql::like(), "%{$t}%")->first() ?? $q()->get()->first(fn($r) => \App\Mcp\Busca::parecido($r->nombre . ' ' . $r->instruccion, $t));
    }

    public static function herramientas(): array
    {
        $params = [
            'instruccion' => ['type' => 'string', 'description' => 'Qué tiene que hacer cada vez, escrito como una orden para vos mismo: «Decime cuánto se vendió y se cobró en la semana y quiénes son los 5 que más deben».'],
            'nombre' => ['type' => 'string', 'description' => 'Nombre corto: «Resumen del lunes».'],
            'frecuencia' => ['type' => 'string', 'enum' => array_keys(RutinasService::FRECUENCIAS), 'description' => 'cada_hora, diaria, habiles (lunes a viernes), semanal (con dias) o mensual (con dia_mes).'],
            'hora' => ['type' => 'string', 'description' => 'HH:MM en 24 horas (08:00, 18:30).'],
            'dias' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Para semanal: lunes, martes, miércoles, jueves, viernes, sábado, domingo.'],
            'dia_mes' => ['type' => 'string', 'description' => 'Para mensual: 1 a 28, o «ultimo».'],
            'canal' => ['type' => 'string', 'enum' => array_keys(RutinasService::CANALES), 'description' => 'Por dónde llega: aviso (campana y celular), whatsapp o mail. Si no lo dice, no lo mandes.'],
            'solo_si_hay' => ['type' => 'boolean', 'description' => 'true si es de vigilancia («avisame sólo si…»): no manda nada cuando no hay novedad.'],
        ];
        return [
            ['nombre' => 'crear_rutina', 'titulo' => 'Encargarle una rutina a Bigcito', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Deja encargado algo para que Bigcito lo haga solo cada tanto y mande el resultado: informes («todos los lunes a las 8 pasame el resumen»), vigilancia («avisame si alguien pasa su límite») o tareas («todos los viernes proponeme a quién mandarle recordatorio»). Corre con los permisos del usuario; lo que sea una acción queda propuesto para confirmar.',
                'parametros' => $params, 'requeridos' => ['instruccion', 'frecuencia'],
                'proponer' => function (User $u, array $a) {
                    $svc = app(RutinasService::class);
                    try { $n = $svc->normalizar($u, $a); } catch (ValidationException $e) { return ['error' => collect($e->errors())->flatten()->first()]; }
                    if (BigcitoRutina::where('user_id', $u->id)->count() >= RutinasService::MAX_POR_USUARIO) return ['error' => 'Ya tenés ' . RutinasService::MAX_POR_USUARIO . ' rutinas. Borrá alguna antes de sumar otra.'];
                    return ['titulo' => "Rutina «{$n['nombre']}»", 'detalle' => $svc->describir(new BigcitoRutina($n)) . ". Va a hacer: {$n['instruccion']}", 'datos' => $n];
                },
                'confirmar' => function (User $u, array $d) {
                    $svc = app(RutinasService::class); $r = $svc->crear($u, $d);
                    return ['texto' => "Listo, quedó la rutina «{$r->nombre}». " . $svc->describir($r) . '. La próxima es el ' . $r->proxima_en->format('d/m \a \l\a\s H:i') . '.', 'url' => \App\Mcp\Busca::url('/rutinas')];
                }],
            ['nombre' => 'mis_rutinas', 'titulo' => 'Mis rutinas', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Lista las rutinas que el usuario le dejó encargadas a Bigcito: qué hacen, cuándo, por dónde llegan, cuándo fue la última vez y qué dijo.',
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(RutinasService::class);
                    return ['rutinas' => BigcitoRutina::where('user_id', $u->id)->orderBy('id')->get()->map(fn($r) => collect($svc->resumir($r))->only(['id', 'nombre', 'instruccion', 'cuando', 'activa', 'proxima', 'ultima', 'ultimo_resultado'])->all())->all(), 'pantalla' => \App\Mcp\Busca::url('/rutinas')];
                }],
            ['nombre' => 'cambiar_rutina', 'titulo' => 'Cambiar, pausar o borrar una rutina', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Cambia una rutina del usuario: pausarla o reanudarla (activa), borrarla (borrar), o cambiarle la hora, los días, la frecuencia, el canal o lo que hace.',
                'parametros' => ['rutina' => ['type' => 'string', 'description' => 'Nombre (o parte) o id de la rutina.'], 'activa' => ['type' => 'boolean', 'description' => 'false la pausa, true la reanuda.'], 'borrar' => ['type' => 'boolean']] + collect($params)->except('nombre')->all(),
                'requeridos' => ['rutina'],
                'proponer' => function (User $u, array $a) {
                    $r = self::buscar($u, $a['rutina']); if (! $r) return ['error' => "No encontré una rutina tuya que se llame «{$a['rutina']}»."];
                    if (! empty($a['borrar'])) return ['titulo' => "Borrar la rutina «{$r->nombre}»", 'datos' => ['id' => $r->id, 'borrar' => true]];
                    $cambios = collect($a)->only(['instruccion', 'frecuencia', 'hora', 'dias', 'dia_mes', 'canal', 'solo_si_hay', 'activa'])->all();
                    if (! $cambios) return ['error' => 'Decime qué querés cambiar: la hora, los días, pausarla o borrarla.'];
                    $svc = app(RutinasService::class);
                    try { $n = $svc->normalizar($u, $cambios + ['nombre' => $r->nombre, 'instruccion' => $r->instruccion, 'frecuencia' => $r->frecuencia, 'dias' => $r->dias, 'dia_mes' => $r->dia_mes, 'hora' => $r->hora, 'canal' => $r->canal, 'solo_si_hay' => $r->solo_si_hay]); } catch (ValidationException $e) { return ['error' => collect($e->errors())->flatten()->first()]; }
                    $pausa = array_key_exists('activa', $cambios) ? ((bool) $cambios['activa'] ? 'Reanudar' : 'Pausar') : 'Cambiar';
                    return ['titulo' => "{$pausa} la rutina «{$r->nombre}»", 'detalle' => array_key_exists('activa', $cambios) && ! $cambios['activa'] ? 'No va a correr hasta que la reanudes.' : $svc->describir(new BigcitoRutina($n)), 'datos' => ['id' => $r->id, 'cambios' => $cambios]];
                },
                'confirmar' => function (User $u, array $d) {
                    $r = BigcitoRutina::where('user_id', $u->id)->findOrFail($d['id']);
                    if (! empty($d['borrar'])) { $n = $r->nombre; $r->delete(); return ['texto' => "Listo, borré la rutina «{$n}»."]; }
                    $svc = app(RutinasService::class); $r = $svc->actualizar($r, $u, $d['cambios']);
                    return ['texto' => $r->activa ? "Listo. «{$r->nombre}»: " . $svc->describir($r) . '.' : "Listo, pausé «{$r->nombre}».", 'url' => \App\Mcp\Busca::url('/rutinas')];
                }],
            ['nombre' => 'correr_rutina', 'titulo' => 'Hacer una rutina ahora', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Hace ahora una rutina del usuario y devuelve el resultado, sin mandarlo por su canal ni mover su horario. Para «corré ahora la rutina del lunes» o para probarla.',
                'parametros' => ['rutina' => ['type' => 'string', 'description' => 'Nombre (o parte) o id.']], 'requeridos' => ['rutina'],
                'ejecutar' => function (User $u, array $a) {
                    $r = self::buscar($u, $a['rutina']); if (! $r) return ['error' => "No encontré una rutina tuya que se llame «{$a['rutina']}»."];
                    $res = app(RutinasService::class)->ejecutar($r, false);
                    return ['rutina' => $r->nombre, 'resultado' => $res['texto'], 'acciones_propuestas' => array_map(fn($p) => $p['titulo'], $res['propuestas'])];
                }],
        ];
    }
}
