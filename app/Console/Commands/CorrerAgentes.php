<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Agentes\AgentesService;
use Illuminate\Console\Command;

// Fase 107: todas las madrugadas, los agentes de cada empresa dejan sus propuestas y el dueño recibe el aviso al celular.
class CorrerAgentes extends Command
{
    protected $signature = 'agentes:correr {--empresa=} {--agente=}';
    protected $description = 'Corre los agentes de Bigcito (cobrador, reposición, precios, cierre, control) y deja las propuestas';

    public function handle(AgentesService $svc): int
    {
        $q = Business::where('is_active', true)->whereNull('suspended_at');
        if ($this->option('empresa')) $q->whereKey((int) $this->option('empresa'));
        $total = 0;
        foreach ($q->get() as $b) {
            if (! $svc->config($b)['activos']) continue;
            $r = $svc->correr($b, $this->option('agente') ? [$this->option('agente')] : null);
            $n = array_sum($r); $total += $n;
            if ($n) $svc->avisar($b, $n);
            $this->line("{$b->name}: " . ($r ? collect($r)->map(fn($v, $k) => "{$k} {$v}")->implode(' · ') : 'sin agentes'));
        }
        $this->info("Propuestas nuevas: {$total}");
        return self::SUCCESS;
    }
}
