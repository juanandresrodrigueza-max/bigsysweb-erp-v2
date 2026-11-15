<?php

namespace App\Console\Commands;

use App\Services\Suscripciones\AprobacionService;
use Illuminate\Console\Command;

// Fase 106: cada hora, avisa a BigSys las altas pendientes que están por bloquearse y las que ya se bloquearon.
class AvisarAprobaciones extends Command
{
    protected $signature = 'aprobaciones:avisar';
    protected $description = 'Avisa a BigSys las altas pendientes por bloquearse y las bloqueadas';

    public function handle(AprobacionService $svc): int
    {
        $r = $svc->avisarPendientes();
        $this->info("Por vencer: {$r['por_vencer']} · bloqueadas: {$r['bloqueadas']}");
        return self::SUCCESS;
    }
}
