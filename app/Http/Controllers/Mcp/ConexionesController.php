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
            'url' => url('/mcp'), 'esDueno' => $dueno, 'conTabs' => $u->puede('configuracion'),
            'ia' => ['cargada' => \App\Support\Ia::tiene($u->business), 'mascara' => \App\Support\Ia::mascara($u->business), 'modelo' => $u->business?->ia_settings['modelo'] ?? \App\Support\Ia::MODELO_DEFAULT, 'modelos' => \App\Support\Ia::MODELOS, 'puedeEditar' => $dueno || $u->puede('configuracion', 'editar'), 'audio' => $u->business ? app(\App\Services\IA\VozFotoService::class)->config($u->business) : null, 'proveedoresAudio' => \App\Services\IA\VozFotoService::PROVEEDORES], 'modos' => McpConexion::MODOS, 'tokenNuevo' => session('tokenNuevo'),
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

    // La clave de IA de la empresa: la usa el asistente y toda la IA del sistema. La paga cada empresa.
    public function guardarIa(Request $r)
    {
        $u = $this->editorIa($r);
        $d = $r->validate(['api_key' => 'nullable|string|max:300', 'modelo' => 'required|in:' . implode(',', array_keys(\App\Support\Ia::MODELOS))]);
        $s = $u->business->ia_settings ?? [];
        if (! empty($d['api_key'])) {
            $k = trim($d['api_key']);
            if (! str_starts_with($k, 'sk-ant-')) return back()->withErrors(['api_key' => 'La clave de Anthropic empieza con sk-ant-.']);
            if ($e = $this->probarClave($k, $d['modelo'])) return back()->withErrors(['api_key' => $e]);
            $s['api_key'] = $k;
        } elseif (empty($s['api_key'])) {
            return back()->withErrors(['api_key' => 'Pegá la clave.']);
        }
        $s['modelo'] = $d['modelo'];
        $u->business->forceFill(['ia_settings' => $s])->save();
        AuditLog::registrar('editar', $u->business, 'Clave de IA de la empresa ' . (! empty($d['api_key']) ? 'cargada' : 'actualizada') . ' (' . $d['modelo'] . ')');
        return back()->with('ok', 'Listo: la IA de tu empresa quedó activa.');
    }

    // Fase 40.3: el proveedor que transcribe los audios (OpenAI o Gemini).
    public function guardarAudio(Request $r)
    {
        $u = $this->editorIa($r);
        $d = $r->validate(['proveedor' => 'required|in:' . implode(',', array_keys(\App\Services\IA\VozFotoService::PROVEEDORES)), 'clave' => 'nullable|string|max:300']);
        app(\App\Services\IA\VozFotoService::class)->guardar($u->business, $d['proveedor'], $d['clave'] ?? null);
        AuditLog::registrar('editar', $u->business, 'Audios de Bigcito con ' . \App\Services\IA\VozFotoService::PROVEEDORES[$d['proveedor']]);
        return back()->with('ok', 'Listo: Bigcito ya entiende audios.');
    }

    public function quitarIa(Request $r)
    {
        $u = $this->editorIa($r);
        $u->business->forceFill(['ia_settings' => null])->save();
        AuditLog::registrar('editar', $u->business, 'Clave de IA de la empresa quitada');
        return back()->with('ok', 'Clave quitada: el asistente vuelve al modo básico.');
    }

    private function editorIa(Request $r)
    {
        $u = $r->user();
        abort_unless($u->business && ! $u->is_superadmin && ($u->esDueno() || $u->puede('configuracion', 'editar')), 403);
        return $u;
    }

    private function probarClave(string $k, string $modelo): ?string
    {
        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $k, 'anthropic-version' => '2023-06-01'])->timeout(20)->post('https://api.anthropic.com/v1/messages', ['model' => $modelo, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'OK']]]);
        } catch (\Throwable $e) { return 'No pude conectarme con Anthropic. Probá de nuevo.'; }
        if ($res->successful()) return null;
        return match ($res->status()) { 401, 403 => 'La clave no es válida.', 400 => str_contains($res->body(), 'credit') ? 'La cuenta de Anthropic no tiene crédito: cargalo en console.anthropic.com → Billing.' : 'Anthropic rechazó la prueba: ' . mb_substr((string) $res->json('error.message'), 0, 150), 429 => 'La cuenta llegó a su límite de uso. Revisalo en console.anthropic.com.', default => 'Anthropic respondió con error ' . $res->status() . '.' };
    }

    private function conexion(Request $r, int $id): McpConexion
    {
        $c = McpConexion::whereNull('revocada_en')->findOrFail($id);
        abort_unless($c->user_id === $r->user()->id || $r->user()->esDueno(), 403);
        return $c;
    }
}
