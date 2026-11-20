<?php

namespace App\Console\Commands;

use App\Services\Migracion\CopiaCompletaService;
use Illuminate\Console\Command;

// Fase 123: la copia completa de la base, cifrada y fuera del servidor. Corre cada noche a las 03:20.
class BackupsCompleta extends Command
{
    protected $signature = 'backups:completa';
    protected $description = 'Copia completa de la base de datos y la configuración, cifrada y subida al disco externo';

    public function handle(CopiaCompletaService $svc): int
    {
        if (! CopiaCompletaService::clave()) { $this->warn('Falta la clave de las copias completas (Panel → Sistema). No se hizo la copia.'); return self::FAILURE; }
        try { $c = $svc->crear('auto'); }
        catch (\Throwable $e) { $this->error('La copia completa falló: ' . $e->getMessage()); report($e); return self::FAILURE; }
        $this->info('Copia completa: ' . round($c->bytes / 1048576, 2) . " MB, {$c->tablas} tablas" . ($c->remoto ? ", subida a {$c->remoto}" : ', sin disco externo (quedó en el servidor)') . '.');
        return self::SUCCESS;
    }
}
