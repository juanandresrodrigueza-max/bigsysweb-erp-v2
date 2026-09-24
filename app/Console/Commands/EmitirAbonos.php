<?php

namespace App\Console\Commands;

use App\Services\Ventas\AbonosService;
use Illuminate\Console\Command;

class EmitirAbonos extends Command
{
    protected $signature = 'abonos:emitir';
    protected $description = 'Genera las facturas de los abonos recurrentes que vencen hoy.';

    public function handle(AbonosService $s): int
    {
        $this->info('Abonos emitidos: ' . $s->emitirVencidos());
        return self::SUCCESS;
    }
}
