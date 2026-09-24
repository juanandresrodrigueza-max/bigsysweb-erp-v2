<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\Migracion\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Copia de seguridad de una empresa, en cola: no bloquea el scheduler ni la pantalla del usuario.
class BackupEmpresaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(public Business $empresa, public string $origen = 'auto', public ?int $userId = null) {}

    public function handle(BackupService $svc): void
    {
        $svc->crear($this->empresa, $this->origen, $this->userId);
    }
}
