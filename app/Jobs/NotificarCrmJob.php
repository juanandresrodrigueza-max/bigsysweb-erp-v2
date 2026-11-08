<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\Integraciones\CrmService;
use App\Support\Cuit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

// Le avisa al CRM que el ERP emitió o anuló un comprobante, o registró un cobro (firmado con el secreto compartido del handoff).
class NotificarCrmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $businessId, public string $evento, public array $datos) {}

    // Lo que se le cuenta al CRM de un cobro (lo usan el registro del cobro y la reconciliación).
    public static function datosCobro(\App\Models\Cobro $cobro): array
    {
        $contact = $cobro->contact ?? \App\Models\Contact::withoutGlobalScopes()->find($cobro->contact_id);
        return ['id' => $cobro->id, 'numero' => $cobro->numeroFormateado(), 'fecha' => $cobro->fecha?->toDateString(), 'cliente' => ['id' => $contact?->id, 'nombre' => $contact?->name], 'total' => (float) $cobro->total, 'saldo' => 0, 'estado' => 'registrado',
            'vendedor' => ($v = ($cobro->cobrador_id ? $cobro->cobrador : null) ?? ($cobro->vendedor_id ? $cobro->vendedor : null)) ? ['id' => $v->id, 'nombre' => $v->nombre, 'email' => $v->email ?: $v->user?->email] : null]; // Fase 74
    }

    public static function avisar(?int $businessId, string $evento, array $datos): void
    {
        if (! $businessId) return;
        $b = Business::withoutGlobalScopes()->find($businessId);
        if ($b && CrmService::activo($b)) self::dispatch($businessId, $evento, $datos);
    }

    public function handle(): void
    {
        $b = Business::withoutGlobalScopes()->find($this->businessId);
        if (! $b || ! CrmService::activo($b)) return;
        $c = CrmService::config($b);
        $json = json_encode(['evento' => $this->evento, 'datos' => $this->datos + ['emisor_cuit' => Cuit::limpiar($b->cuit)], 'fecha' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ref = ($this->datos['numero'] ?? $this->datos['id'] ?? null); $t = microtime(true);
        try {
            $r = Http::timeout(10)->withHeaders(['Content-Type' => 'application/json', 'X-BigSys-Evento' => $this->evento, 'X-BigSys-Firma' => hash_hmac('sha256', $json, $c['secreto'])])->withBody($json, 'application/json')->post(CrmService::url($b, '/api/erp/webhook'));
        } catch (\Throwable $e) {
            \App\Services\Integraciones\CrmSaludService::registrar($b->id, 'salida', $this->evento, 'error', ['referencia' => $ref, 'ms' => (int) ((microtime(true) - $t) * 1000), 'detalle' => 'Sin conexión: ' . $e->getMessage()]);
            throw $e;
        }
        $ms = (int) ((microtime(true) - $t) * 1000);
        if (! $r->successful() && $r->status() !== 404) {
            \App\Services\Integraciones\CrmSaludService::registrar($b->id, 'salida', $this->evento, 'error', ['referencia' => $ref, 'http' => $r->status(), 'ms' => $ms, 'detalle' => mb_substr($r->body(), 0, 200)]);
            throw new \RuntimeException("El CRM respondió {$r->status()} al aviso {$this->evento}.");
        }
        \App\Services\Integraciones\CrmSaludService::registrar($b->id, 'salida', $this->evento, $r->status() === 404 ? 'ignorado' : 'ok', ['referencia' => $ref, 'http' => $r->status(), 'ms' => $ms, 'detalle' => $r->status() === 404 ? 'El CRM no tiene esta empresa con la integración activa' : null]);
    }
}
