<?php

namespace App\Console\Commands;

use App\Services\Afip\CaeaService;
use Illuminate\Console\Command;

// Fase 126: todos los días, el CAEA de la quincena (y el de la próxima dentro de los 5 días anteriores), lo emitido con
// CAEA que falte informar y los puntos de venta CAEA sin movimiento de las quincenas terminadas.
class ArcaCaeaCommand extends Command
{
    protected $signature = 'arca:caea {--empresa=}';
    protected $description = 'Pide los CAEA, informa lo emitido con CAEA y los puntos de venta sin movimiento';

    public function handle(CaeaService $svc): int
    {
        $e = $this->option('empresa') ? (int) $this->option('empresa') : null;
        foreach ($svc->pedirProximos($e) as $r) $r['ok'] ? $this->line("{$r['empresa']}: CAEA {$r['caea']} ({$r['periodo']}/{$r['orden']})") : $this->warn("{$r['empresa']}: sin CAEA {$r['periodo']}/{$r['orden']}: {$r['error']}");
        $n = $svc->informarPendientes($e);
        $this->line("Informados: {$n['informados']} · pendientes: {$n['pendientes']} · rechazados: {$n['rechazados']}");
        foreach ($svc->informarSinMovimiento($e) as $r) $r['ok'] ? $this->line("{$r['empresa']}: PV {$r['pv']} sin movimiento informado ({$r['caea']})") : $this->warn("{$r['empresa']}: PV {$r['pv']} sin movimiento: {$r['error']}");
        return self::SUCCESS;
    }
}
