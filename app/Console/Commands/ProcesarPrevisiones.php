<?php

namespace App\Console\Commands;

use App\Services\Fondos\PrevisionesService;
use Illuminate\Console\Command;

class ProcesarPrevisiones extends Command
{
    protected $signature = 'previsiones:procesar';
    protected $description = 'Registra las previsiones automáticas vencidas y avisa las que vencen pronto';

    public function handle(PrevisionesService $svc): int
    {
        [$r, $a] = $svc->procesar();
        $this->info("Previsiones: {$r} registradas, {$a} avisadas.");
        return self::SUCCESS;
    }
}
