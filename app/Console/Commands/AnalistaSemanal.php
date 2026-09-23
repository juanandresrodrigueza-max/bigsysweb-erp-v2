<?php

namespace App\Console\Commands;

use App\Models\Alerta;
use App\Models\Business;
use App\Services\IA\AnalistaService;
use Illuminate\Console\Command;

// Todos los lunes: el analista revisa cada empresa y deja como alerta lo crítico.
class AnalistaSemanal extends Command
{
    protected $signature = 'analista:semanal';
    protected $description = 'Corre el analista de negocio y genera alertas con lo importante';

    public function handle(AnalistaService $svc): int
    {
        foreach (Business::where('is_active', true)->get() as $b) {
            \App\Jobs\AnalistaEmpresaJob::dispatch($b); // en cola: recorre todas las ventas y puede llamar a la IA
            $this->line("{$b->name}: encolado");
        }
        return self::SUCCESS;
    }
}
