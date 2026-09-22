<?php

namespace App\Console\Commands;

use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Console\Command;

class RevisarSuscripciones extends Command
{
    protected $signature = 'suscripciones:revisar';
    protected $description = 'Pasa a gracia o suspende las suscripciones vencidas y avisa antes de cada vencimiento';

    public function handle(SuscripcionService $service): int
    {
        $r = $service->revisar();
        $this->info("A gracia: {$r['a_gracia']} · suspendidas: {$r['suspendidas']} · avisadas: {$r['avisadas']}");
        return self::SUCCESS;
    }
}
