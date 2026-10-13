<?php

namespace App\Mcp;

use App\Models\McpConexion;
use App\Models\User;

// Fase 28: el protocolo MCP (JSON-RPC 2.0 por HTTP). Recibe un mensaje (o un lote) ya autenticado y devuelve la respuesta.
class Servidor
{
    public const VERSIONES = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private Catalogo $catalogo) {}

    // null = notificación (no lleva respuesta).
    public function atender(array $msg, User $u, McpConexion $con): ?array
    {
        $id = $msg['id'] ?? null; $metodo = $msg['method'] ?? null; $p = (array) ($msg['params'] ?? []);
        if (($msg['jsonrpc'] ?? null) !== '2.0' || ! is_string($metodo)) return $this->error($id, -32600, 'Pedido inválido.');
        if (! array_key_exists('id', $msg)) return null;
        try {
            $r = match ($metodo) {
                'initialize' => $this->initialize($p, $u),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->catalogo->paraMcp($u, $con)],
                'tools/call' => $this->llamar($p, $u, $con),
                'prompts/list' => ['prompts' => array_map(fn($x) => array_intersect_key($x, array_flip(['name', 'title', 'description', 'arguments'])), Recursos::prompts())],
                'prompts/get' => Recursos::prompt((string) ($p['name'] ?? ''), (array) ($p['arguments'] ?? [])),
                'resources/list' => ['resources' => Recursos::recursos($u)],
                'resources/read' => Recursos::leer((string) ($p['uri'] ?? ''), $u),
                'resources/templates/list' => ['resourceTemplates' => []],
                'logging/setLevel', 'completion/complete' => (object) [],
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        }
        if ($r === null) return $this->error($id, -32601, "Método no soportado: {$metodo}");
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $r];
    }

    private function initialize(array $p, User $u): array
    {
        $pedida = (string) ($p['protocolVersion'] ?? '');
        return ['protocolVersion' => in_array($pedida, self::VERSIONES, true) ? $pedida : self::VERSIONES[0],
            'capabilities' => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false], 'resources' => ['listChanged' => false, 'subscribe' => false]],
            'serverInfo' => ['name' => 'bigsysweb-erp', 'title' => 'BigSys ERP', 'version' => '28.0'],
            'instructions' => Recursos::instrucciones($u)];
    }

    private function llamar(array $p, User $u, McpConexion $con): array
    {
        $nombre = (string) ($p['name'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('Falta el nombre de la herramienta.');
        $r = $this->catalogo->llamar($u, $nombre, (array) ($p['arguments'] ?? []), 'mcp', $con);
        $error = isset($r['error']);
        return ['content' => [['type' => 'text', 'text' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'structuredContent' => (object) $r, 'isError' => $error];
    }

    public function error($id, int $code, string $msg): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }
}
