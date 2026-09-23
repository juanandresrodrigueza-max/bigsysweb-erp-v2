<?php

namespace App\Console\Commands;

use App\Services\Ventas\CobranzasService;
use Illuminate\Console\Command;

class RecordarCobranzas extends Command
{
    protected $signature = 'cobranzas:recordar';
    protected $description = 'Manda los recordatorios de vencimiento configurados por cada empresa.';

    public function handle(CobranzasService $s): int
    {
        $this->info('Recordatorios enviados: ' . $s->correrAutomaticos());
        return self::SUCCESS;
    }
}
