<?php

namespace App\Console\Commands;

use App\Services\Fondos\CotizacionService;
use Illuminate\Console\Command;

class ActualizarCotizaciones extends Command
{
    protected $signature = 'cotizaciones:actualizar';
    protected $description = 'Baja las cotizaciones del dólar (oficial, blue, MEP, tarjeta).';

    public function handle(CotizacionService $s): int
    {
        $r = $s->actualizar();
        $this->info(isset($r['error']) ? "No se pudo actualizar: {$r['error']}" : 'Cotizaciones: ' . json_encode($r));
        return isset($r['error']) ? self::FAILURE : self::SUCCESS;
    }
}
