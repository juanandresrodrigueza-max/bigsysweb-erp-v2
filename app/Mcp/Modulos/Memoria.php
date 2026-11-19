<?php

namespace App\Mcp\Modulos;

use App\Models\User;
use App\Services\IA\MemoriaService;

// Fase 119.5: la memoria de Bigcito por voz. Son anotaciones, no cambian datos del negocio: se guardan al toque, sin pedir confirmación.
class Memoria
{
    public static function herramientas(): array
    {
        return [
            ['nombre' => 'recordar', 'titulo' => 'Recordar algo', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Anota algo que el usuario quiere que tengas presente de acá en más: apodos («Pepe es Pérez SRL»), cuentas o valores por defecto («mi caja es la Caja Central», «a López siempre con la lista 2»), cómo quiere que le hables («contestame corto»), datos del negocio que no están en el sistema. Usala cuando diga «acordate», «recordá», «de ahora en más», «siempre que…», o cuando te corrija algo que conviene no volver a preguntar. Escribí el texto corto y claro, en tercera persona si hace falta. No guardes claves, contraseñas ni datos de tarjetas.',
                'parametros' => ['texto' => ['type' => 'string', 'description' => 'Lo que hay que recordar, en una frase.'], 'para_toda_la_empresa' => ['type' => 'boolean', 'description' => 'true sólo si pidió que valga para todos los usuarios de la empresa.']],
                'requeridos' => ['texto'],
                'ejecutar' => function (User $u, array $a) {
                    $r = app(MemoriaService::class)->recordar($u, (string) $a['texto'], (bool) ($a['para_toda_la_empresa'] ?? false));
                    return isset($r['error']) ? $r : ['anotado' => $r['memoria']->texto, 'reemplazo_una_anterior' => $r['reemplazo'], 'vale_para' => $r['memoria']->user_id ? 'este usuario' : 'toda la empresa'];
                }],
            ['nombre' => 'que_recordas', 'titulo' => 'Qué recuerda Bigcito', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Lista todo lo que tenés anotado para este usuario y para su empresa.',
                'ejecutar' => fn(User $u, array $a) => ['recuerdos' => app(MemoriaService::class)->para($u), 'pantalla' => \App\Mcp\Busca::url('/rutinas')]],
            ['nombre' => 'olvidar', 'titulo' => 'Olvidar algo', 'modulo' => null, 'permiso' => null, 'tipo' => 'consulta',
                'descripcion' => 'Borra algo que tenías anotado, por su id o por parte del texto («olvidate de lo de Pepe»).',
                'parametros' => ['que' => ['type' => 'string', 'description' => 'Id o parte del texto a olvidar.']], 'requeridos' => ['que'],
                'ejecutar' => fn(User $u, array $a) => app(MemoriaService::class)->olvidar($u, (string) $a['que'])],
        ];
    }
}
