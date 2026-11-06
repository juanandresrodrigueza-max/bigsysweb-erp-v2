<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\SistemaConfig;
use Illuminate\Console\Command;

// Recalcula el precio en pesos de los planes desde su precio en dólares. Con --cotizacion se guarda la nueva cotización.
// Lo que ya pagó cada empresa no cambia: el precio nuevo se cobra en la próxima renovación.
class PlanesPesos extends Command
{
    protected $signature = 'planes:actualizar-pesos {--cotizacion=}';
    protected $description = 'Pasa los precios en dólares de los planes a pesos con la cotización del sistema';

    public function handle(): int
    {
        if ($c = (float) $this->option('cotizacion')) SistemaConfig::set('cotizacion_usd', $c);
        $cot = (float) SistemaConfig::get('cotizacion_usd', 1515);
        foreach (Plan::whereNotNull('precio_usd')->get() as $p) {
            $p->update(['price_monthly' => round($p->precio_usd * $cot, -2), 'price_yearly' => round(($p->precio_usd_anual ?? $p->precio_usd * 10) * $cot, -2)]);
            $this->line(sprintf('%-8s USD %6s → $ %s', $p->name, $p->precio_usd, number_format($p->price_monthly, 0, ',', '.')));
        }
        $this->info("Cotización usada: $ {$cot} por dólar.");
        return self::SUCCESS;
    }
}
