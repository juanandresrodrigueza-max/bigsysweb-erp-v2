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
        $r = Http::timeout(10)->withHeaders(['Content-Type' => 'application/json', 'X-BigSys-Evento' => $this->evento, 'X-BigSys-Firma' => hash_hmac('sha256', $json, $c['secreto'])])->withBody($json, 'application/json')->post(CrmService::url($b, '/api/erp/webhook'));
        if (! $r->successful() && $r->status() !== 404) throw new \RuntimeException("El CRM respondió {$r->status()} al aviso {$this->evento}.");
    }
}
