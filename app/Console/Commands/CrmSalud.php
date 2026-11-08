<?php

namespace App\Console\Commands;

use App\Services\Integraciones\CrmSaludService;
use Illuminate\Console\Command;

// Fase 68: cada 15 minutos mira si el CRM responde y avisa si se cortó; una vez por día reconcilia lo de las últimas 48 horas.
class CrmSalud extends Command
{
    protected $signature = 'crm:salud {--reconciliar : Además vuelve a mandar lo de las últimas 48 horas}';
    protected $description = 'Salud de la conexión con el CRM: ping, alerta y reconciliación';

    public function handle(CrmSaludService $svc): int
    {
        foreach ($svc->revisarTodas((bool) $this->option('reconciliar')) as $id => $estado) $this->line("empresa {$id}: {$estado}");
        return self::SUCCESS;
    }
}
