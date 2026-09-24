<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Ventas\AvisosDuenoService;
use Illuminate\Console\Command;

// Cada 15 minutos: manda el resumen diario a la hora configurada y las alertas críticas nuevas.
class AvisosDueno extends Command
{
    protected $signature = 'avisos:dueno';
    protected $description = 'Envía al dueño el resumen del día y las alertas críticas por WhatsApp';

    public function handle(AvisosDuenoService $svc): int
    {
        $r = 0; $c = 0;
        foreach (Business::where('is_active', true)->get() as $b) {
            try { if ($svc->enviarResumenSiCorresponde($b)) $r++; $c += $svc->enviarCriticas($b); } catch (\Throwable $e) { $this->error("{$b->name}: {$e->getMessage()}"); }
        }
        $this->info("Resúmenes: {$r} · críticas: {$c}");
        return self::SUCCESS;
    }
}
