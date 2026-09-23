<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Migracion\BackupService;
use Illuminate\Console\Command;

// Copia de seguridad diaria de cada empresa activa que la tenga encendida.
class BackupsDiario extends Command
{
    protected $signature = 'backups:diario';
    protected $description = 'Genera la copia de seguridad diaria de cada empresa';

    public function handle(BackupService $svc): int
    {
        $n = 0;
        foreach (Business::where('is_active', true)->where('backup_auto', true)->get() as $b) {
            // Con QUEUE_CONNECTION=sync corre acá mismo; con database/redis lo toma el worker (php artisan queue:work).
            try { \App\Jobs\BackupEmpresaJob::dispatch($b, 'auto'); $n++; } catch (\Throwable $e) { $this->error("{$b->name}: {$e->getMessage()}"); }
        }
        $this->info("Copias encoladas: {$n}");
        return self::SUCCESS;
    }
}
