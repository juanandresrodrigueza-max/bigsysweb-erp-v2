<?php

namespace App\Console\Commands;

use App\Services\Equipo\EncargosService;
use Illuminate\Console\Command;

// Fase 120.6: recuerda los encargos vencidos (una vez por día cada uno).
class EncargosRecordar extends Command
{
    protected $signature = 'encargos:recordar';
    protected $description = 'Recuerda los encargos vencidos a quien los tiene y le avisa a quien los pidió';

    public function handle(EncargosService $svc): int
    {
        $this->info('Encargos recordados: ' . $svc->recordarVencidos());
        return self::SUCCESS;
    }
}
