<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Canales\WhatsappPlantillasService;
use Illuminate\Console\Command;

// Fase 120.1: revisa en Meta el estado de las plantillas de WhatsApp de cada empresa conectada (y pide las que falten).
class WhatsappPlantillas extends Command
{
    protected $signature = 'whatsapp:plantillas';
    protected $description = 'Sincroniza las plantillas de WhatsApp (para escribir fuera de las 24 horas) de cada empresa conectada';

    public function handle(WhatsappPlantillasService $pl): int
    {
        $n = 0; $ok = 0;
        foreach (Business::where('is_active', true)->whereNotNull('whatsapp_settings')->get() as $b) {
            if (! $pl->puedePlantillas($b)) continue;
            // Las que ya tienen todo aprobado no hace falta volver a consultarlas todos los días.
            if ($pl->estado($b)['aprobadas'] === count(WhatsappPlantillasService::CATALOGO)) { $ok++; continue; }
            $n++; $r = $pl->sincronizar($b);
            if (isset($r['error'])) $this->warn("{$b->name}: {$r['error']}");
        }
        $this->info("Plantillas de WhatsApp: {$n} empresas revisadas, {$ok} ya completas.");
        return self::SUCCESS;
    }
}
