<?php

namespace App\Services\Agentes;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Propuesta;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Fase 107 · Bigcito trabaja de noche. Corre los agentes de cada empresa (05:45), guarda sus propuestas sin repetir las que ya están
// pendientes, vence las que quedaron viejas, le avisa al dueño al celular y ejecuta lo que el dueño aprueba. Nada se hace solo:
// la regla de la casa (las acciones se confirman) vale también para los agentes.
class AgentesService
{
    public const AGENTES = [
        'cobrador'   => ['label' => 'Cobrador',          'clase' => CobradorAgente::class,       'que' => 'Cada mañana mira quién debe, decide a quién escribir según su historial y redacta el WhatsApp o mail. Vos aprobás y sale.'],
        'reposicion' => ['label' => 'Reposición',        'clase' => ReposicionAgente::class,     'que' => 'Arma las órdenes de compra sugeridas por proveedor con lo que está bajo mínimo o sin stock, y la plata que hace falta.'],
        'precios'    => ['label' => 'Precios',           'clase' => PreciosAgente::class,        'que' => 'Cuando sube el costo de un artículo propone el precio nuevo respetando tu margen, y avisa qué quedó por debajo del mínimo.'],
        'cierre'     => ['label' => 'Cierre del mes',    'clase' => CierreAgente::class,         'que' => 'Los primeros días del mes revisa comprobantes sin asiento, gastos sin categoría, cajas con diferencia, y propone los ajustes para que el contador sólo acepte.'],
        'control'    => ['label' => 'Control interno',   'clase' => ControlInternoAgente::class, 'que' => 'Compara a cada usuario con el resto: anulaciones, descuentos, cajas que no cuadran, ventas bajo lista, cargas a deshora. Si alguien se sale de lo normal, te lo cuenta con la evidencia.'],
    ];
    public const DEFAULT = ['activos' => ['cobrador', 'reposicion', 'precios', 'cierre', 'control'], 'push' => true, 'ultimo' => null];
    public const DIAS_VENCE = 7;

    public function config(Business $b): array { return array_replace(self::DEFAULT, $b->agentes ?? []); }

    public function guardarConfig(Business $b, array $d): void
    {
        $c = $this->config($b);
        if (array_key_exists('activos', $d)) $c['activos'] = array_values(array_intersect(array_keys(self::AGENTES), (array) $d['activos']));
        if (array_key_exists('push', $d)) $c['push'] = (bool) $d['push'];
        $b->update(['agentes' => $c]);
    }

    public function agente(string $nombre): Agente { return app(self::AGENTES[$nombre]['clase']); }

    // Corre los agentes activos (o los pedidos) y devuelve cuántas propuestas nuevas dejó cada uno. Con el dueño como usuario para los scopes.
    public function correr(Business $b, ?array $solo = null, bool $forzar = false): array
    {
        $cfg = $this->config($b); $nombres = $solo ?? $cfg['activos']; $r = [];
        $dueno = User::withoutGlobalScopes()->find($b->owner_id) ?? User::withoutGlobalScopes()->where('business_id', $b->id)->orderBy('id')->first();
        if (! $dueno) return $r;
        $prev = Auth::user(); Auth::setUser($dueno);
        try {
            foreach ($nombres as $n) {
                if (! isset(self::AGENTES[$n])) continue;
                try {
                    $props = $this->agente($n)->proponer($b);
                    $r[$n] = $this->guardar($b, $n, $props);
                } catch (\Throwable $e) { Log::warning("Agente {$n} en {$b->name}: " . $e->getMessage()); $r[$n] = 0; }
            }
        } finally { if ($prev) Auth::setUser($prev); else Auth::logout(); }
        if (! $forzar) $b->update(['agentes' => array_replace($cfg, ['ultimo' => now()->toDateTimeString()])]);
        return $r;
    }

    // Guarda sin repetir: misma clave pendiente = se actualiza (los números del día); rechazada hace menos de 7 días = no se insiste.
    // Lo que el agente ya no propone y tiene más de 7 días, vence.
    private function guardar(Business $b, string $agente, array $props): int
    {
        $nuevas = 0; $claves = [];
        $rechazadas = Propuesta::withoutGlobalScopes()->where('business_id', $b->id)->where('agente', $agente)->where('estado', 'rechazada')->where('resuelta_en', '>=', now()->subDays(self::DIAS_VENCE))->pluck('clave')->flip();
        foreach ($props as $p) {
            $claves[] = $p['clave'];
            if (isset($rechazadas[$p['clave']])) continue;
            $fila = ['titulo' => mb_substr($p['titulo'], 0, 200), 'detalle' => $p['detalle'] ?? null, 'datos' => $p['datos'] ?? null, 'accion' => $p['accion'] ?? null, 'importe' => $p['importe'] ?? null, 'prioridad' => $p['prioridad'] ?? 2];
            $ya = Propuesta::withoutGlobalScopes()->where('business_id', $b->id)->where('agente', $agente)->where('clave', $p['clave'])->where('estado', 'pendiente')->first();
            if ($ya) { $ya->update($fila); continue; }
            Propuesta::create($fila + ['business_id' => $b->id, 'agente' => $agente, 'clave' => $p['clave'], 'estado' => 'pendiente']);
            $nuevas++;
        }
        Propuesta::withoutGlobalScopes()->where('business_id', $b->id)->where('agente', $agente)->where('estado', 'pendiente')->whereNotIn('clave', $claves ?: [''])->where('created_at', '<', now()->subDays(self::DIAS_VENCE))->update(['estado' => 'vencida', 'resuelta_en' => now()]);
        return $nuevas;
    }

    public function resolver(Propuesta $p, User $u, bool $aprobar, ?string $motivo = null): Propuesta
    {
        abort_unless($p->estado === 'pendiente', 422, 'Esta propuesta ya fue resuelta.');
        if (! $aprobar) {
            $p->update(['estado' => 'rechazada', 'motivo' => $motivo ? mb_substr($motivo, 0, 200) : null, 'resuelta_en' => now(), 'resuelta_por' => $u->id]);
            AuditLog::registrar('propuesta_rechazada', $p, mb_substr("Propuesta de Bigcito rechazada: {$p->titulo}" . ($motivo ? " ({$motivo})" : ''), 0, 250));
            return $p;
        }
        $resultado = $p->soloAviso() ? 'Visto.' : DB::transaction(fn() => $this->agente($p->agente)->ejecutar($p, $u));
        $p->update(['estado' => 'aprobada', 'resultado' => mb_substr($resultado, 0, 2000), 'resuelta_en' => now(), 'resuelta_por' => $u->id]);
        AuditLog::registrar('propuesta_aprobada', $p, mb_substr("Propuesta de Bigcito aprobada: {$p->titulo} → {$resultado}", 0, 250)); // descripcion es varchar(255)
        return $p;
    }

    public function pendientes(Business $b): \Illuminate\Support\Collection
    {
        return Propuesta::withoutGlobalScopes()->where('business_id', $b->id)->pendientes()->orderBy('prioridad')->orderByDesc('importe')->orderBy('id')->get();
    }

    // Al dueño, al celular: "Bigcito te dejó N propuestas". Una vez por día, sólo si hay nuevas.
    public function avisar(Business $b, int $nuevas): void
    {
        if ($nuevas <= 0 || ! $this->config($b)['push']) return;
        $dueno = User::withoutGlobalScopes()->find($b->owner_id); if (! $dueno) return;
        $total = $this->pendientes($b)->count();
        try { app(\App\Services\Avisos\CelularService::class)->enviar($dueno, "Bigcito te dejó {$nuevas} propuesta" . ($nuevas > 1 ? 's' : ''), ($total > $nuevas ? "{$total} esperan tu visto bueno. " : '') . 'Aprobalas de a un toque.', '/propuestas'); }
        catch (\Throwable $e) { Log::warning('Aviso de propuestas: ' . $e->getMessage()); }
    }

    public static function pesos($n): string { return '$ ' . number_format((float) $n, 0, ',', '.'); }
}
