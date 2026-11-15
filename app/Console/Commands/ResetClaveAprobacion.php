<?php

namespace App\Console\Commands;

use App\Services\Suscripciones\AprobacionService;
use Illuminate\Console\Command;

// Fase 106: si se perdió la clave de aprobación o no queda ningún dueño, se borra desde el servidor (hace falta acceso al servidor,
// no alcanza con el panel). Después, el primer superadmin que la cargue en Panel → Sistema queda como dueño.
class ResetClaveAprobacion extends Command
{
    protected $signature = 'aprobacion:reset-clave {--si : No preguntar}';
    protected $description = 'Borra la clave de aprobación y sus dueños; la próxima carga desde el panel arranca de cero';

    public function handle(AprobacionService $svc): int
    {
        if (! $this->option('si') && ! $this->confirm('¿Borrar la clave de aprobación y quiénes la administran (' . $svc->nombresDuenos() . ')?')) return self::SUCCESS;
        $svc->resetearClave();
        \App\Models\AuditLog::registrar('editar', null, 'Clave de aprobación reseteada desde el servidor');
        $this->info('Listo. La próxima clave que se cargue en Panel → Sistema define al nuevo dueño.');
        return self::SUCCESS;
    }
}
