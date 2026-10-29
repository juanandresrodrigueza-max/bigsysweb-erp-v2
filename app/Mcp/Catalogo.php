<?php

namespace App\Mcp;

use App\Models\AuditLog;
use App\Models\IaLlamada;
use App\Models\IaPropuesta;
use App\Models\McpConexion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase 28: el catálogo único de herramientas de IA. Lo usan el asistente del sistema y el servidor MCP (Claude, ChatGPT…).
// Cada herramienta es una consulta (responde al instante) o una acción (arma una propuesta y recién se ejecuta cuando el
// usuario la confirma). Todas respetan los permisos del rol del usuario y el modo de la conexión, y quedan registradas.
//
// Definición de una herramienta (array):
//   nombre, descripcion, modulo, permiso (ver|crear|editar|anular|exportar), tipo (consulta|accion), delicada (bool),
//   parametros [nombre => esquema JSON], requeridos [..],
//   consulta: ejecutar(User, array): array
//   acción:   proponer(User, array): ['titulo', 'detalle', 'datos'] | ['error' => '...'];  confirmar(User, array $datos): ['texto', 'url'?]
class Catalogo
{
    public const MODULOS = [Modulos\General::class, Modulos\Clientes::class, Modulos\Ventas::class, Modulos\Stock::class, Modulos\Compras::class, Modulos\Fondos::class, Modulos\Contable::class, Modulos\Personal::class, Modulos\Gestion::class, Modulos\Consejos::class];
    public const VENCE_MINUTOS = 30;
    public const LIMITE_POR_MINUTO = 60;

    private ?array $cache = null;

    public function todas(): array
    {
        if ($this->cache !== null) return $this->cache;
        $out = [];
        foreach (self::MODULOS as $m) foreach ($m::herramientas() as $h) $out[$h['nombre']] = $h + ['delicada' => false, 'requeridos' => [], 'parametros' => []];
        foreach ($this->meta() as $h) $out[$h['nombre']] = $h;
        return $this->cache = $out;
    }

    // Herramientas para confirmar o cancelar una propuesta: están siempre que la conexión pueda hacer acciones.
    private function meta(): array
    {
        return [
            ['nombre' => 'confirmar_accion', 'descripcion' => 'Ejecuta una acción que propusiste antes, con el propuesta_id que devolvió. Llamala SOLO después de mostrarle la propuesta al usuario y que diga que sí.', 'modulo' => null, 'permiso' => null, 'tipo' => 'meta', 'delicada' => false,
                'parametros' => ['propuesta_id' => ['type' => 'string', 'description' => 'El id de la propuesta.']], 'requeridos' => ['propuesta_id']],
            ['nombre' => 'cancelar_accion', 'descripcion' => 'Descarta una propuesta que el usuario no quiere.', 'modulo' => null, 'permiso' => null, 'tipo' => 'meta', 'delicada' => false,
                'parametros' => ['propuesta_id' => ['type' => 'string']], 'requeridos' => ['propuesta_id']],
        ];
    }

    // Nivel de la conexión que necesita cada herramienta.
    public static function nivel(array $h): string
    {
        return $h['tipo'] === 'consulta' ? 'lectura' : ($h['delicada'] ? 'delicadas' : 'acciones');
    }

    public function permitida(User $u, array $h, ?McpConexion $con = null): bool
    {
        if ($h['tipo'] === 'meta') return ! $con || $con->permite('acciones');
        if ($con && ! $con->permite(self::nivel($h))) return false;
        if ($h['modulo'] && ! $u->puede($h['modulo'], $h['permiso'] ?? 'ver')) return false;
        return true;
    }

    public function disponibles(User $u, ?McpConexion $con = null): array
    {
        return array_values(array_filter($this->todas(), fn($h) => $this->permitida($u, $h, $con)));
    }

    public function esquema(array $h): array
    {
        return ['type' => 'object', 'properties' => (object) $h['parametros'], 'required' => array_values($h['requeridos'])];
    }

    // Formato MCP (tools/list).
    public function paraMcp(User $u, McpConexion $con): array
    {
        return array_map(fn($h) => ['name' => $h['nombre'], 'title' => $h['titulo'] ?? null, 'description' => $this->descripcion($h), 'inputSchema' => $this->esquema($h),
            'annotations' => ['readOnlyHint' => $h['tipo'] === 'consulta', 'destructiveHint' => (bool) $h['delicada'], 'openWorldHint' => false]], $this->disponibles($u, $con));
    }

    // Formato de la API de Anthropic (asistente interno).
    public function paraAnthropic(User $u): array
    {
        return array_map(fn($h) => ['name' => $h['nombre'], 'description' => $this->descripcion($h), 'input_schema' => $this->esquema($h)], array_values(array_filter($this->disponibles($u), fn($h) => $h['tipo'] !== 'meta')));
    }

    private function descripcion(array $h): string
    {
        $extra = match ($h['tipo']) { 'accion' => ' [Acción: devuelve una propuesta con propuesta_id; mostrásela al usuario y, si confirma, llamá a confirmar_accion.' . ($h['delicada'] ? ' Acción delicada.' : '') . ']', default => '' };
        return $h['descripcion'] . $extra;
    }

    // Una llamada: consulta → datos; acción → propuesta guardada; meta → confirmar / cancelar.
    public function llamar(User $u, string $nombre, array $args, string $origen = 'mcp', ?McpConexion $con = null): array
    {
        $h = $this->todas()[$nombre] ?? null;
        if (! $h) return ['error' => "No existe la herramienta {$nombre}."];
        if (! $this->permitida($u, $h, $con)) return ['error' => $con && $h['tipo'] !== 'meta' && ! $con->permite(self::nivel($h))
            ? "Esta conexión está en modo \"" . (McpConexion::MODOS[$con->modo] ?? $con->modo) . "\". Para usar {$nombre} el dueño tiene que cambiarla en Configuración → Conexiones IA."
            : "Tu usuario no tiene permiso para {$nombre}."];
        $clave = "ia:{$u->id}";
        if (RateLimiter::tooManyAttempts($clave, self::LIMITE_POR_MINUTO)) return ['error' => 'Demasiadas llamadas seguidas. Esperá un minuto.'];
        RateLimiter::hit($clave, 60);
        if ($falta = array_values(array_filter($h['requeridos'], fn($r) => ! isset($args[$r]) || $args[$r] === '' || $args[$r] === null))) return ['error' => 'Falta: ' . implode(', ', $falta) . '.'];

        $t0 = microtime(true); $tipo = $h['tipo'] === 'consulta' ? 'consulta' : ($h['tipo'] === 'meta' ? 'ejecucion' : 'propuesta');
        try {
            $r = match ($h['tipo']) {
                'consulta' => ($h['ejecutar'])($u, $args),
                'accion' => $this->proponer($u, $h, $args, $origen, $con),
                default => $nombre === 'confirmar_accion' ? $this->confirmar($u, (string) $args['propuesta_id'], $con) : $this->cancelar($u, (string) $args['propuesta_id']),
            };
            $this->log($u, $con, $origen, $nombre, $tipo, ! isset($r['error']), $r['error'] ?? null, $t0);
            return $r;
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->implode(' ');
            $this->log($u, $con, $origen, $nombre, $tipo, false, $msg, $t0);
            return ['error' => $msg];
        } catch (HttpException $e) {
            $this->log($u, $con, $origen, $nombre, $tipo, false, $e->getMessage(), $t0);
            return ['error' => $e->getMessage() ?: 'No se pudo.'];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->log($u, $con, $origen, $nombre, $tipo, false, 'No encontrado', $t0);
            return ['error' => 'No encontré lo que pediste.'];
        }
    }

    private function proponer(User $u, array $h, array $args, string $origen, ?McpConexion $con): array
    {
        $p = ($h['proponer'])($u, $args);
        if (isset($p['error'])) return $p;
        $prop = IaPropuesta::create(['business_id' => $u->business_id, 'user_id' => $u->id, 'conexion_id' => $con?->id, 'origen' => $origen, 'herramienta' => $h['nombre'],
            'titulo' => mb_substr($p['titulo'], 0, 300), 'detalle' => $p['detalle'] ?? null, 'datos' => $p['datos'] ?? [], 'expira_en' => now()->addMinutes(self::VENCE_MINUTOS)]);
        return ['propuesta_id' => $prop->id, 'accion' => $h['nombre'], 'titulo' => $prop->titulo, 'detalle' => $prop->detalle, 'delicada' => (bool) $h['delicada'], 'vence' => $prop->expira_en->toIso8601String(),
            'instruccion' => 'Todavía no se hizo nada. Mostrale al usuario el título y el detalle y preguntale si confirma. Si dice que sí, llamá a confirmar_accion con este propuesta_id.'];
    }

    public function confirmar(User $u, string $id, ?McpConexion $con = null): array
    {
        $p = IaPropuesta::where('user_id', $u->id)->find($id);
        if (! $p) return ['error' => 'No encuentro esa propuesta.'];
        if ($p->estado !== 'pendiente') return ['error' => "Esa propuesta ya está {$p->estado}."];
        if ($p->expira_en->isPast()) { $p->update(['estado' => 'vencida']); return ['error' => 'La propuesta venció. Pedila de nuevo.']; }
        $h = $this->todas()[$p->herramienta] ?? null;
        if (! $h || ! $this->permitida($u, $h, $con)) return ['error' => 'Ya no tenés permiso para esa acción.'];
        try {
            $r = DB::transaction(fn() => ($h['confirmar'])($u, $p->datos));
        } catch (\Throwable $e) {
            $p->update(['estado' => 'error', 'resultado' => ['error' => mb_substr($e->getMessage(), 0, 300)]]);
            throw $e;
        }
        $p->update(['estado' => 'ejecutada', 'ejecutada_en' => now(), 'resultado' => $r]);
        $quien = $p->origen === 'mcp' ? 'la IA conectada' . ($con ? " ({$con->nombre})" : '') : 'el asistente';
        AuditLog::registrar('ia_ejecuto', null, "{$quien} ejecutó \"{$p->titulo}\" confirmado por {$u->name}");
        return ['ok' => true] + $r;
    }

    public function cancelar(User $u, string $id): array
    {
        $p = IaPropuesta::where('user_id', $u->id)->where('estado', 'pendiente')->find($id);
        if (! $p) return ['error' => 'No encuentro esa propuesta pendiente.'];
        $p->update(['estado' => 'cancelada']);
        return ['ok' => true, 'texto' => 'Listo, no se hizo nada.'];
    }

    private function log(User $u, ?McpConexion $con, string $origen, string $nombre, string $tipo, bool $ok, ?string $error, float $t0): void
    {
        try {
            IaLlamada::create(['business_id' => $u->business_id, 'user_id' => $u->id, 'conexion_id' => $con?->id, 'origen' => $origen, 'herramienta' => $nombre, 'tipo' => $tipo, 'ok' => $ok, 'error' => $error ? mb_substr($error, 0, 300) : null, 'ms' => (int) ((microtime(true) - $t0) * 1000), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }
}
