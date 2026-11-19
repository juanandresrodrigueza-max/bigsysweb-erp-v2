<?php

namespace App\Mcp\Modulos;

use App\Mcp\Busca;
use App\Models\Encargo;
use App\Models\User;
use App\Services\Equipo\EncargosService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

// Fase 120.6: encargos entre personas. «Decile a Pedro que prepare el pedido 12 para las 17», «¿qué tengo pendiente?», «ya hice lo del banco».
class Encargos
{
    private static function fecha(?string $v): ?Carbon
    {
        $v = trim((string) $v); if ($v === '') return null;
        try { $f = Carbon::parse($v); } catch (\Throwable $e) { return null; }
        // Sólo la fecha (sin hora) = hasta el final de ese día.
        return preg_match('/\d{1,2}:\d{2}/', $v) ? $f : $f->setTime(23, 59);
    }

    public static function herramientas(): array
    {
        return [
            ['nombre' => 'encargar', 'titulo' => 'Dejarle un encargo a alguien del equipo', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'accion',
                'descripcion' => 'Le deja un encargo (una tarea concreta) a otra persona del equipo de la empresa, o a uno mismo como recordatorio. A la persona le llega por WhatsApp (si tiene celular cargado) y por la campana; cuando contesta HECHO, le avisa a quien lo pidió. Usala para «decile a Pedro que…», «pedile a Ana que…», «recordale a Luis…», «anotame que tengo que…».',
                'parametros' => [
                    'para' => ['type' => 'string', 'description' => 'Nombre de la persona del equipo. «yo» si es para uno mismo.'],
                    'texto' => ['type' => 'string', 'description' => 'Qué hay que hacer, corto y concreto: «preparar el pedido 12».'],
                    'para_cuando' => ['type' => 'string', 'description' => 'Para cuándo, como fecha y hora ISO (2026-11-20T17:00) o sólo fecha (2026-11-20). Vacío si no tiene plazo.'],
                    'detalle' => ['type' => 'string', 'description' => 'Aclaraciones, si las hay.'],
                ],
                'requeridos' => ['para', 'texto'],
                'proponer' => function (User $u, array $a) {
                    $svc = app(EncargosService::class);
                    $p = $svc->persona($u, (string) ($a['para'] ?? ''));
                    if (! $p) return ['error' => 'No encuentro a «' . ($a['para'] ?? '') . '» en el equipo (o hay más de una persona con ese nombre). Están: ' . $svc->equipo($u)->pluck('name')->implode(', ') . '.'];
                    $f = self::fecha($a['para_cuando'] ?? null);
                    if ($f && $f->lt(now()->subMinutes(5))) return ['error' => 'Esa fecha ya pasó. ¿Para cuándo lo querés?'];
                    $texto = trim((string) ($a['texto'] ?? '')); if ($texto === '') return ['error' => '¿Qué le encargo?'];
                    $via = $p->id === $u->id ? 'Queda anotado en tus encargos.' : ($p->mobile && ! empty($u->business->whatsapp_settings['token']) ? 'Le llega por WhatsApp y por la campana.' : 'Le llega por la campana (no tiene celular cargado o la empresa no tiene WhatsApp conectado).');
                    return ['titulo' => ($p->id === $u->id ? 'Anotarte: ' : "Encargarle a {$p->name}: ") . \Illuminate\Support\Str::limit($texto, 120), 'detalle' => ($f ? 'Para ' . EncargosService::cuando($f) . '. ' : 'Sin plazo. ') . $via,
                        'datos' => ['para_id' => $p->id, 'texto' => $texto, 'vence' => $f?->toDateTimeString(), 'detalle' => $a['detalle'] ?? null]];
                },
                'confirmar' => function (User $u, array $d) {
                    try { $e = app(EncargosService::class)->crear($u, User::where('business_id', $u->business_id)->findOrFail($d['para_id']), $d['texto'], ! empty($d['vence']) ? Carbon::parse($d['vence']) : null, $d['detalle'] ?? null, null, 'bigcito'); }
                    catch (ValidationException $x) { throw new \RuntimeException(collect($x->errors())->flatten()->first()); }
                    return ['texto' => $e->para_user_id === $u->id ? 'Anotado en tus encargos.' : 'Listo, se lo dejé a ' . $e->para?->name . ($e->aviso === 'whatsapp' ? ' y le avisé por WhatsApp.' : ' (le aparece en la campana).'), 'url' => Busca::url('/encargos')];
                }],
            ['nombre' => 'mis_encargos', 'titulo' => 'Mis encargos', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Los encargos pendientes: los que me dejaron a mí (lo que tengo que hacer) y los que yo le dejé a otros y todavía no hicieron. Con plazo, quién lo pidió y si está vencido.',
                'parametros' => [],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(EncargosService::class);
                    $mios = $svc->paraMi($u)->map(fn($e) => ['id' => $e->id, 'texto' => $e->texto, 'de' => $e->de?->name, 'para_cuando' => $e->vence_en ? EncargosService::cuando($e->vence_en) : null, 'vencido' => $e->vencido()])->values()->all();
                    $otros = Encargo::where('business_id', $u->business_id)->where('de_user_id', $u->id)->where('para_user_id', '!=', $u->id)->where('estado', 'pendiente')->with('para:id,name')->orderBy('id')->limit(50)->get()
                        ->map(fn($e) => ['id' => $e->id, 'texto' => $e->texto, 'a' => $e->para?->name, 'para_cuando' => $e->vence_en ? EncargosService::cuando($e->vence_en) : null, 'vencido' => $e->vencido()])->all();
                    return ['tengo_que_hacer' => $mios, 'le_encargue_a_otros' => $otros, 'url' => Busca::url('/encargos'),
                        'resumen' => (count($mios) ? 'Tenés ' . count($mios) . ' encargo(s) para hacer' : 'No tenés encargos para hacer') . (count($otros) ? ' y ' . count($otros) . ' que encargaste siguen pendientes.' : '.')];
                }],
            // Marcar como hecho es inmediato (se puede reabrir): «ya hice lo del banco».
            ['nombre' => 'encargo_hecho', 'titulo' => 'Marcar un encargo como hecho', 'modulo' => 'dashboard', 'permiso' => 'ver', 'tipo' => 'consulta',
                'descripcion' => 'Marca como hecho un encargo que la persona tenía pendiente (se le avisa a quien lo pidió). Pasá el id (de mis_encargos) o unas palabras del encargo. Usala cuando diga «ya hice…», «listo lo de…», «terminé…».',
                'parametros' => ['encargo' => ['type' => 'string', 'description' => 'Id del encargo o palabras que lo identifiquen'], 'nota' => ['type' => 'string', 'description' => 'Comentario opcional para quien lo pidió']],
                'requeridos' => ['encargo'],
                'ejecutar' => function (User $u, array $a) {
                    $svc = app(EncargosService::class); $mios = $svc->paraMi($u); $ref = trim((string) ($a['encargo'] ?? ''));
                    if ($mios->isEmpty()) return ['error' => 'No tenés encargos pendientes.'];
                    $e = ctype_digit($ref) ? $mios->firstWhere('id', (int) $ref) : null;
                    if (! $e) { $c = $mios->filter(fn($x) => Busca::parecido($x->texto, $ref)); if ($c->count() === 1) $e = $c->first(); elseif ($c->count() > 1) return ['error' => 'Hay más de uno que coincide: ' . $c->map(fn($x) => "#{$x->id} {$x->texto}")->implode(' · ') . '. Decime cuál.']; }
                    if (! $e) return ['error' => 'No encuentro ese encargo. Los pendientes son: ' . $mios->map(fn($x) => "#{$x->id} {$x->texto}")->implode(' · ')];
                    $svc->hecho($e, $u, $a['nota'] ?? null);
                    return ['hecho' => true, 'encargo' => $e->texto, 'resumen' => "Marcado como hecho: {$e->texto}." . ($e->de_user_id !== $u->id ? ' Le avisé a ' . $e->de?->name . '.' : ''), 'quedan' => $mios->count() - 1];
                }],
        ];
    }
}
