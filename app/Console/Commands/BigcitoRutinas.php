<?php

namespace App\Console\Commands;

use App\Services\IA\RutinasService;
use Illuminate\Console\Command;

// Fase 119.4: corre las rutinas de Bigcito que ya tenían que salir (el programador lo llama cada 5 minutos).
class BigcitoRutinas extends Command
{
    protected $signature = 'bigcito:rutinas';
    protected $description = 'Hace las rutinas que los usuarios le dejaron encargadas a Bigcito y manda el resultado';

    public function handle(RutinasService $svc): int
    {
        $r = $svc->correrPendientes();
        $this->info("Rutinas: {$r['corridas']} corridas, {$r['entregadas']} entregadas, {$r['sin_novedad']} sin novedad, {$r['errores']} con error.");
        return self::SUCCESS;
    }
}
