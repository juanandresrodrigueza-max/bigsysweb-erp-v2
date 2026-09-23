<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\Integraciones\CrmService;
use App\Services\Integraciones\CrmSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Manda un lote de clientes o artículos al CRM. Reintenta con espera creciente si el CRM no responde.
class SincronizarCrmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $businessId, public string $tipo, public array $ids) {}

    public function handle(): void
    {
        $b = Business::withoutGlobalScopes()->find($this->businessId);
        if (! $b || ! CrmService::activo($b)) return;
        CrmSyncService::enviar($b, $this->tipo, $this->ids);
    }
}
