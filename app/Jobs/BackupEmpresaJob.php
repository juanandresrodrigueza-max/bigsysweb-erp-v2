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
        $bk = $svc->crear($this->empresa, $this->origen, $this->userId);
        // Fase 78: la copia se verifica en el acto y, si hay disco externo, se sube. Si algo falla, queda anotado en la copia y la salud lo muestra.
        try { $svc->verificar($bk); } catch (\Throwable $e) { $bk->forceFill(['verificado_en' => now(), 'verificacion' => ['ok' => false, 'tablas' => 0, 'problemas' => [mb_substr($e->getMessage(), 0, 200)]]])->save(); }
        try { $svc->subirRemoto($bk); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning("Backup remoto {$this->empresa->id}: " . $e->getMessage()); }
    }
}
