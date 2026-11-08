<?php

namespace App\Console\Commands;

use App\Services\Integraciones\CrmAvisosService;
use Illuminate\Console\Command;

// Fase 73: una vez por día, lo crítico del ERP al vendedor en el CRM y las tareas vencidas del CRM a la campana del ERP.
class CrmAvisos extends Command
{
    protected $signature = 'crm:avisos';
    protected $description = 'Avisos cruzados con el CRM: lo crítico al vendedor, las tareas vencidas a la campana';

    public function handle(CrmAvisosService $svc): int
    {
        foreach ($svc->revisarTodas() as $id => $r) $this->line("empresa {$id}: " . json_encode($r, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
