<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Catalogo;
use App\Models\AuditLog;
use App\Models\IaLlamada;
use App\Models\IaPropuesta;
use App\Models\McpConexion;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Fase 28.1: "Conexiones IA". Cada usuario ve y maneja las suyas; el dueño ve las de toda la empresa.
class ConexionesController extends Controller
{
    public function index(Request $r, Catalogo $cat)
    {
        $u = $r->user(); $dueno = $u->esDueno();
        $con = McpConexion::with('user:id,name')->whereNull('revocada_en')->when(! $dueno, fn($q) => $q->where('user_id', $u->id))->orderByDesc('ultimo_uso')->get();
        $ids = $dueno ? null : [$u->id];
        return Inertia::render('Mcp/Conexiones', [
            'url' => url('/mcp'), 'esDueno' => $dueno, 'conTabs' => $u->puede('configuracion'), 'modos' => McpConexion::MODOS, 'tokenNuevo' => session('tokenNuevo'),
            'conexiones' => $con->map(fn($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'usuario' => $c->user?->name, 'propia' => $c->user_id === $u->id, 'modo' => $c->modo, 'tipo' => $c->client_id ? 'OAuth' : 'Token personal', 'ultimo_uso' => $c->ultimo_uso?->diffForHumans(), 'usos' => $c->usos, 'creada' => $c->created_at->format('d/m/Y')]),
            'llamadas' => IaLlamada::with('user:id,name')->when($ids, fn($q) => $q->whereIn('user_id', $ids))->orderByDesc('id')->limit(40)->get()->map(fn($l) => ['id' => $l->id, 'cuando' => $l->created_at?->format('d/m H:i'), 'usuario' => $l->user?->name, 'origen' => $l->origen, 'herramienta' => $l->herramienta, 'tipo' => $l->tipo, 'ok' => $l->ok, 'error' => $l->error, 'ms' => $l->ms]),
            'propuestas' => IaPropuesta::with('user:id,name')->when($ids, fn($q) => $q->whereIn('user_id', $ids))->orderByDesc('created_at')->limit(30)->get()->map(fn($p) => ['id' => $p->id, 'cuando' => $p->created_at?->format('d/m H:i'), 'usuario' => $p->user?->name, 'titulo' => $p->titulo, 'estado' => $p->estado === 'pendiente' && $p->expira_en->isPast() ? 'vencida' : $p->estado, 'origen' => $p->origen]),
            'herramientas' => collect($cat->disponibles($u))->where('tipo', '!=', 'meta')->map(fn($h) => ['nombre' => $h['nombre'], 'titulo' => $h['titulo'] ?? $h['nombre'], 'tipo' => $h['tipo'], 'delicada' => (bool) $h['delicada']])->values(),
        ]);
    }

    // Token personal (para Claude Desktop, Cursor, scripts): no vence; se muestra una sola vez.
    public function crearToken(Request $r)
    {
        $d = $r->validate(['nombre' => 'required|string|max:100', 'modo' => 'required|in:' . implode(',', array_keys(McpConexion::MODOS))]);
        $u = $r->user(); abort_if($u->is_superadmin, 403);
        $t = $u->createToken('MCP · ' . $d['nombre'], ['mcp']);
        $c = McpConexion::create(['business_id' => $u->business_id, 'user_id' => $u->id, 'nombre' => $d['nombre'], 'token_id' => $t->accessToken->id, 'modo' => $d['modo']]);
        AuditLog::registrar('crear', $c, "{$u->name} creó el token MCP {$d['nombre']} (" . McpConexion::MODOS[$d['modo']] . ')');
        return back()->with('tokenNuevo', $t->plainTextToken);
    }

    public function modo(Request $r, int $id)
    {
        $c = $this->conexion($r, $id);
        $d = $r->validate(['modo' => 'required|in:' . implode(',', array_keys(McpConexion::MODOS))]);
        $c->update(['modo' => $d['modo']]);
        AuditLog::registrar('editar', $c, "Conexión IA {$c->nombre}: " . McpConexion::MODOS[$d['modo']]);
        return back()->with('ok', 'Modo actualizado.');
    }

    public function revocar(Request $r, int $id)
    {
        $c = $this->conexion($r, $id);
        if ($c->token_id) \Laravel\Sanctum\PersonalAccessToken::whereKey($c->token_id)->delete();
        $c->update(['revocada_en' => now(), 'refresh_hash' => null]);
        IaPropuesta::where('conexion_id', $c->id)->where('estado', 'pendiente')->update(['estado' => 'cancelada']);
        AuditLog::registrar('eliminar', $c, "Conexión IA {$c->nombre} desconectada");
        return back()->with('ok', 'Conexión desconectada.');
    }

    private function conexion(Request $r, int $id): McpConexion
    {
        $c = McpConexion::whereNull('revocada_en')->findOrFail($id);
        abort_unless($c->user_id === $r->user()->id || $r->user()->esDueno(), 403);
        return $c;
    }
}
