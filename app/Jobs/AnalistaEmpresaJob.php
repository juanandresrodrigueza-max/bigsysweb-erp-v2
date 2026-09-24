<?php

namespace App\Jobs;

use App\Models\Alerta;
use App\Models\Business;
use App\Services\IA\AnalistaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Hallazgos semanales del analista para una empresa: corre en cola porque recorre todas las ventas y puede llamar a la IA.
class AnalistaEmpresaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(public Business $empresa) {}

    public function handle(AnalistaService $svc): void
    {
        $h = collect($svc->hallazgos($this->empresa))->whereIn('sev', ['critica', 'aviso'])->take(5);
        foreach ($h as $x) Alerta::emitir(['business_id' => $this->empresa->id, 'modulo' => 'estadisticas', 'tipo' => 'analista_' . $x['cat'], 'severidad' => $x['sev'] === 'critica' ? 'critica' : 'aviso', 'titulo' => $x['titulo'], 'detalle' => $x['detalle'], 'url' => '/estadisticas/analista']);
    }
}
