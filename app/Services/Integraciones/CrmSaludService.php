<?php

namespace App\Services\Integraciones;

use App\Jobs\NotificarCrmJob;
use App\Jobs\SincronizarCrmJob;
use App\Models\Alerta;
use App\Models\Business;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\CrmEvento;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Fase 68 · Salud de la conexión ERP ↔ CRM: qué viajó y qué no, qué quedó en la cola, si el CRM responde, alerta al dueño cuando se
// corta, reintento de lo que falló y reconciliación diaria (lo de las últimas 48 horas se vuelve a mandar, idempotente del otro lado).
class CrmSaludService
{
    public const PING_CACHE = 10; // minutos

    public static function registrar(int $businessId, string $direccion, string $evento, string $estado, array $x = []): void
    {
        try {
            CrmEvento::create(['business_id' => $businessId, 'direccion' => $direccion, 'evento' => mb_substr($evento, 0, 60), 'referencia' => isset($x['referencia']) ? mb_substr((string) $x['referencia'], 0, 120) : null, 'estado' => $estado,
                'http' => $x['http'] ?? null, 'ms' => $x['ms'] ?? null, 'detalle' => isset($x['detalle']) ? mb_substr((string) $x['detalle'], 0, 300) : null, 'created_at' => now()]);
            if (random_int(1, 100) === 1) CrmEvento::where('business_id', $businessId)->where('created_at', '<', now()->subDays(60))->delete(); // poda ocasional
        } catch (\Throwable) {}
    }

    private static function like(int $bid): string { return '%"businessId";i:' . $bid . ';%'; }

    // Qué hay en la cola para esta empresa (jobs serializados: la propiedad businessId va como s:10:"businessId";i:N;).
    public function cola(Business $b): array
    {
        $l = self::like($b->id);
        $pend = DB::table('jobs')->where('payload', 'like', $l)->where(fn($q) => $q->where('payload', 'like', '%NotificarCrmJob%')->orWhere('payload', 'like', '%SincronizarCrmJob%'))->count();
        $fall = DB::table('failed_jobs')->where('payload', 'like', $l)->where(fn($q) => $q->where('payload', 'like', '%NotificarCrmJob%')->orWhere('payload', 'like', '%SincronizarCrmJob%'))->get(['uuid', 'failed_at', 'exception']);
        return ['pendientes' => $pend, 'fallidos' => $fall->count(), 'fallidos_uuids' => $fall->pluck('uuid')->all(), 'ultimo_fallo' => $fall->max('failed_at')];
    }

    // ¿El CRM contesta? Cacheado 10 minutos salvo que se pida en vivo.
    public function ping(Business $b, bool $enVivo = false): array
    {
        $f = function () use ($b) { $t = microtime(true); $r = CrmService::probar($b); return $r + ['ms' => (int) ((microtime(true) - $t) * 1000), 'cuando' => now()->toIso8601String()]; };
        if ($enVivo) { $r = $f(); Cache::put("crm_ping_{$b->id}", $r, now()->addMinutes(self::PING_CACHE)); return $r; }
        return Cache::remember("crm_ping_{$b->id}", now()->addMinutes(self::PING_CACHE), $f);
    }

    public function resumen(Business $b, bool $pingEnVivo = false): array
    {
        $q = fn() => CrmEvento::where('business_id', $b->id);
        $ultSalida = $q()->where('direccion', 'salida')->where('estado', 'ok')->latest('created_at')->first();
        $ultEntrada = $q()->where('direccion', 'entrada')->where('estado', 'ok')->latest('created_at')->first();
        $err24 = $q()->whereIn('estado', ['error', 'rechazado'])->where('created_at', '>=', now()->subDay())->count();
        $ok24 = $q()->where('estado', 'ok')->where('created_at', '>=', now()->subDay())->count();
        $ultimos = $q()->latest('created_at')->limit(20)->get()->map(fn($e) => ['cuando' => $e->created_at->format('d/m H:i'), 'direccion' => $e->direccion, 'evento' => $e->evento, 'referencia' => $e->referencia, 'estado' => $e->estado, 'http' => $e->http, 'ms' => $e->ms, 'detalle' => $e->detalle])->all();
        $cola = $this->cola($b); $ping = CrmService::activo($b) ? $this->ping($b, $pingEnVivo) : ['ok' => false, 'detalle' => 'Integración inactiva.'];
        $estado = ! CrmService::activo($b) ? 'inactiva' : (! $ping['ok'] || $cola['fallidos'] > 0 ? 'rojo' : ($err24 > 0 || $cola['pendientes'] > 20 ? 'amarillo' : 'verde'));
        $texto = match ($estado) {
            'inactiva' => 'La integración con el CRM está apagada.',
            'rojo' => ! $ping['ok'] ? 'El CRM no responde: ' . ($ping['detalle'] ?? '') . ' Lo que se genere queda en la cola y se reintenta solo.' : "Hay {$cola['fallidos']} envío(s) que agotaron los reintentos. Tocá Reintentar cuando el CRM esté andando.",
            'amarillo' => ($err24 ? "{$err24} error(es) en las últimas 24 horas (se reintentan solos). " : '') . ($cola['pendientes'] > 20 ? "{$cola['pendientes']} envíos esperando en la cola." : ''),
            default => 'Todo en orden: el CRM responde' . (isset($ping['ms']) ? " en {$ping['ms']} ms" : '') . ", {$ok24} eventos OK en las últimas 24 horas.",
        };
        return ['estado' => $estado, 'texto' => trim($texto), 'ping' => $ping, 'cola' => $cola, 'errores_24h' => $err24, 'ok_24h' => $ok24,
            'ultimo_enviado' => $ultSalida ? ['cuando' => $ultSalida->created_at->diffForHumans(), 'evento' => $ultSalida->evento, 'referencia' => $ultSalida->referencia] : null,
            'ultimo_recibido' => $ultEntrada ? ['cuando' => $ultEntrada->created_at->diffForHumans(), 'evento' => $ultEntrada->evento, 'referencia' => $ultEntrada->referencia] : null,
            'ultimos' => $ultimos];
    }

    // Vuelve a encolar los envíos al CRM que agotaron los reintentos.
    public function reintentar(Business $b): int
    {
        $uuids = $this->cola($b)['fallidos_uuids']; if (! $uuids) return 0;
        Artisan::call('queue:retry', ['id' => $uuids]);
        self::registrar($b->id, 'salida', 'reintento', 'ok', ['referencia' => count($uuids) . ' envío(s) fallido(s)', 'detalle' => 'Vueltos a la cola a pedido']);
        return count($uuids);
    }

    // Reconciliación: lo tocado en las últimas horas se vuelve a mandar. Del lado del CRM es idempotente (external_id / id del comprobante).
    public function reconciliar(Business $b, int $horas = 48): array
    {
        if (! CrmService::activo($b)) return ['clientes' => 0, 'articulos' => 0, 'comprobantes' => 0, 'cobros' => 0];
        $desde = now()->subHours($horas); $r = ['clientes' => 0, 'articulos' => 0, 'comprobantes' => 0, 'cobros' => 0];
        if (CrmService::config($b)['api_key'] !== '') {
            $cli = Contact::withoutGlobalScopes()->where('business_id', $b->id)->whereIn('type', ['customer', 'both'])->where('updated_at', '>=', $desde)->pluck('id')->all();
            foreach (array_chunk($cli, 200) as $lote) SincronizarCrmJob::dispatch($b->id, 'contactos', $lote); $r['clientes'] = count($cli);
            $prod = Product::withoutGlobalScopes()->where('business_id', $b->id)->where('updated_at', '>=', $desde)->pluck('id')->all();
            foreach (array_chunk($prod, 200) as $lote) SincronizarCrmJob::dispatch($b->id, 'productos', $lote); $r['articulos'] = count($prod);
        }
        foreach (Comprobante::withoutGlobalScopes()->where('business_id', $b->id)->where('direccion', 'venta')->whereIn('estado', ['emitido', 'anulado'])->where('updated_at', '>=', $desde)->limit(500)->get() as $c) {
            NotificarCrmJob::avisar($b->id, $c->estado === 'anulado' ? 'comprobante.anulado' : 'comprobante.emitido', $c->estado === 'anulado' ? ['id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'reconciliacion' => true] : WebhookService::comprobante($c->fresh(['items', 'contact'])) + ['reconciliacion' => true]); $r['comprobantes']++;
        }
        foreach (Cobro::withoutGlobalScopes()->where('business_id', $b->id)->where('estado', '!=', 'anulado')->where('updated_at', '>=', $desde)->limit(500)->get() as $cobro) { NotificarCrmJob::avisar($b->id, 'cobro.registrado', NotificarCrmJob::datosCobro($cobro) + ['reconciliacion' => true]); $r['cobros']++; }
        self::registrar($b->id, 'salida', 'reconciliacion', 'ok', ['referencia' => "{$r['clientes']} clientes · {$r['articulos']} artículos · {$r['comprobantes']} comprobantes · {$r['cobros']} cobros", 'detalle' => "Últimas {$horas} horas"]);
        return $r;
    }

    // Alerta al dueño cuando el CRM no responde o hay envíos caídos; se resuelve sola cuando vuelve.
    public function alertar(Business $b, array $resumen): void
    {
        $activa = Alerta::withoutGlobalScopes()->where('business_id', $b->id)->where('tipo', 'crm_conexion')->whereNull('resuelta_en')->first();
        if ($resumen['estado'] === 'rojo') {
            if (! $activa) Alerta::emitir(['business_id' => $b->id, 'modulo' => 'configuracion', 'tipo' => 'crm_conexion', 'severidad' => 'critica', 'modelo' => 'Business', 'modelo_id' => $b->id, 'titulo' => 'La conexión con el CRM está caída', 'detalle' => $resumen['texto'], 'url' => '/configuracion/crm']);
        } elseif ($activa) { $activa->update(['resuelta_en' => now()]); self::registrar($b->id, 'salida', 'conexion', 'ok', ['detalle' => 'El CRM volvió a responder']); }
    }

    // Cada 15 minutos para todas las empresas con CRM; con $reconciliar, además la pasada diaria.
    public function revisarTodas(bool $reconciliar = false): array
    {
        $out = [];
        foreach (Business::where('is_active', true)->get() as $b) {
            if (! CrmService::activo($b)) continue;
            try { $r = $this->resumen($b, true); $this->alertar($b, $r); if ($reconciliar) $r['reconciliado'] = $this->reconciliar($b); $out[$b->id] = $r['estado']; } catch (\Throwable $e) { $out[$b->id] = 'error: ' . $e->getMessage(); }
        }
        return $out;
    }
}
