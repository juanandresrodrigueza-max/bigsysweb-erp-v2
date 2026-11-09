<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Monitoreo\MedicionService;
use Illuminate\Console\Command;

// Mide páginas desde adentro (sin navegador): tiempo total, cantidad de consultas y las más lentas. Para encontrar cuellos de botella.
// Uso: php artisan erp:medir /comprobantes /estadisticas --usuario=1 --top=3
// Fase 77: con --nocturna mide las páginas clave de las empresas más grandes y guarda la historia (la salud avisa si algo se pone lento).
class MedirPaginas extends Command
{
    protected $signature = 'erp:medir {urls?*} {--usuario=} {--top=3} {--umbral=} {--nocturna : Ronda automática sobre las empresas más grandes, guardando los resultados}';
    protected $description = 'Mide tiempo y consultas SQL de páginas del ERP (usa el usuario indicado o el primero)';

    public function handle(MedicionService $svc): int
    {
        $umbral = (int) ($this->option('umbral') ?: MedicionService::umbral());
        if ($this->option('nocturna')) {
            $r = $svc->nocturna($this->argument('urls') ?: null);
            foreach ($r as $m) $this->line(sprintf('%s %-14s %-20s %5d ms · %3d consultas', $m['ms'] > $umbral ? '<fg=red>LENTA</>' : '<fg=green>  ok </>', mb_substr($m['empresa'], 0, 14), $m['url'], $m['ms'], $m['consultas']));
            $this->info(count($r) . ' mediciones guardadas.');
            return self::SUCCESS;
        }
        $u = $this->option('usuario') ? User::find($this->option('usuario')) : User::whereNotNull('business_id')->orderBy('id')->first();
        if (! $u) { $this->error('No hay usuario.'); return 1; }
        $lentas = 0;
        foreach ($svc->medir($this->argument('urls') ?: MedicionService::PAGINAS, $u, (int) $this->option('top')) as $url => $m) {
            $this->line(sprintf('%s %-48s %5d ms · %3d consultas · %5d ms en SQL', $m['ms'] > $umbral ? '<fg=red>LENTA</>' : '<fg=green>  ok </>', $url . ' (' . $m['http'] . ')', $m['ms'], $m['consultas'], $m['sql_ms']));
            foreach ($m['lentas'] as $q) $this->line('        ' . str_pad((string) $q['ms'], 5, ' ', STR_PAD_LEFT) . ' ms  ' . preg_replace('/\s+/', ' ', $q['sql']));
            if ($m['ms'] > $umbral) $lentas++;
        }
        return $lentas ? 1 : 0;
    }
}
