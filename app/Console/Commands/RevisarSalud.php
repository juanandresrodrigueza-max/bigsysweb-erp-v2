<?php

namespace App\Console\Commands;

use App\Services\Monitoreo\SaludService;
use Illuminate\Console\Command;

class RevisarSalud extends Command
{
    protected $signature = 'salud:revisar';
    protected $description = 'Revisa la salud del servidor y avisa por mail si hay controles críticos';

    public function handle(SaludService $svc): int
    {
        $r = $svc->revisarYAvisar();
        $this->info("Salud: {$r['estado']} · {$r['criticos']} críticos · {$r['avisados']} avisados.");
        return $r['estado'] === 'critico' ? self::FAILURE : self::SUCCESS;
    }
}
