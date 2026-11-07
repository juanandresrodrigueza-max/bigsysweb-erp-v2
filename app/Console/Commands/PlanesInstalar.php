<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;

// Carga los planes en dólares (Gratis, Inicial, Negocio, Empresa) sólo si todavía no están, para no pisar
// precios cambiados desde el panel. Con --forzar los vuelve a los valores de fábrica.
class PlanesInstalar extends Command
{
    protected $signature = 'planes:instalar {--forzar}';
    protected $description = 'Carga los planes de BigSysWeb si faltan';

    public function handle(): int
    {
        if (! $this->option('forzar') && Plan::where('slug', 'negocio')->exists()) { $this->info('Los planes ya estaban cargados.'); return self::SUCCESS; }
        $this->call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
        foreach (Plan::where('is_active', true)->orderBy('orden')->get() as $p) $this->line(sprintf('  %-8s USD %6s  ·  $ %s por mes', $p->name, $p->precio_usd, number_format($p->price_monthly, 0, ',', '.')));
        $this->info('Planes cargados.');
        return self::SUCCESS;
    }
}
