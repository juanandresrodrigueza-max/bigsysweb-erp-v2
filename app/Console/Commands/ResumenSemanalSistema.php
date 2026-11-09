<?php

namespace App\Console\Commands;

use App\Services\Monitoreo\ResumenSemanalService;
use Illuminate\Console\Command;

// Fase 76: los lunes temprano, el resumen de la semana del sistema (uso real, errores, salud, copias, soporte) al mail de monitoreo.
class ResumenSemanalSistema extends Command
{
    protected $signature = 'sistema:resumen-semanal';
    protected $description = 'Manda al administrador de BigSys el resumen semanal del sistema con uso real';

    public function handle(ResumenSemanalService $svc): int
    {
        $a = $svc->enviar();
        $this->line($a ? "Enviado a {$a}" : 'Sin mail de monitoreo configurado (Superadmin → Salud).');
        return self::SUCCESS;
    }
}
