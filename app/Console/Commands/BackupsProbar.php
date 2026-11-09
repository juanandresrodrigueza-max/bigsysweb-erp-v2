<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\Business;
use App\Services\Migracion\BackupService;
use Illuminate\Console\Command;

// Fase 78: la última copia de cada empresa se abre y se comprueba todos los días; una vez por semana además se ensaya su restauración
// (en una transacción que se deshace) y se sube la que falte al disco externo.
class BackupsProbar extends Command
{
    protected $signature = 'backups:probar {--ensayo : Además ensaya la restauración de la última copia de cada empresa}';
    protected $description = 'Verifica las copias de seguridad (y con --ensayo prueba restaurarlas sin tocar los datos)';

    public function handle(BackupService $svc): int
    {
        $mal = 0;
        foreach (Business::where('is_active', true)->get() as $b) {
            $bk = Backup::withoutGlobalScopes()->where('business_id', $b->id)->latest('id')->first();
            if (! $bk) { $this->line("{$b->name}: sin copias"); continue; }
            $v = $svc->verificar($bk); $linea = "{$b->name}: " . ($v['ok'] ? "verificada ({$v['tablas']} tablas)" : 'FALLA: ' . implode(' ', $v['problemas']));
            if (! $v['ok']) $mal++;
            if ($this->option('ensayo') && $v['ok']) { $e = $svc->ensayarRestauracion($bk); $linea .= ' · ensayo ' . ($e['ok'] ? 'OK' : 'FALLA: ' . $e['detalle']); if (! $e['ok']) $mal++; }
            if (! $bk->remoto && BackupService::remotoConfigurado()) { try { $svc->subirRemoto($bk); $linea .= ' · subida al disco externo'; } catch (\Throwable $x) { $linea .= ' · NO se pudo subir: ' . $x->getMessage(); $mal++; } }
            $this->line($linea);
        }
        return $mal ? 1 : self::SUCCESS;
    }
}
