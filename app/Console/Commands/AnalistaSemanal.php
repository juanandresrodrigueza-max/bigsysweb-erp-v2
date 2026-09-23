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
            try {
                $h = collect($svc->hallazgos($b))->whereIn('sev', ['critica', 'aviso'])->take(5);
                foreach ($h as $x) Alerta::emitir(['business_id' => $b->id, 'modulo' => 'estadisticas', 'tipo' => 'analista_' . $x['cat'], 'severidad' => $x['sev'] === 'critica' ? 'critica' : 'aviso', 'titulo' => $x['titulo'], 'detalle' => $x['detalle'], 'url' => '/estadisticas/analista']);
                $this->line("{$b->name}: " . $h->count() . ' hallazgos');
            } catch (\Throwable $e) { $this->error("{$b->name}: {$e->getMessage()}"); }
        }
        return self::SUCCESS;
    }
}
