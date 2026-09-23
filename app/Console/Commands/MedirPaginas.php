<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Mide páginas desde adentro (sin navegador): tiempo total, cantidad de consultas y las más lentas. Para encontrar cuellos de botella.
// Uso: php artisan erp:medir /comprobantes /estadisticas --usuario=1 --top=3
class MedirPaginas extends Command
{
    protected $signature = 'erp:medir {urls*} {--usuario=} {--top=3} {--umbral=300}';
    protected $description = 'Mide tiempo y consultas SQL de páginas del ERP (usa el usuario indicado o el primero)';

    public function handle(Kernel $kernel): int
    {
        $u = $this->option('usuario') ? User::find($this->option('usuario')) : User::whereNotNull('business_id')->orderBy('id')->first();
        if (! $u) { $this->error('No hay usuario.'); return 1; }
        $lentas = 0; $n = 0; $sql = 0.0; $top = []; $topN = (int) $this->option('top');
        // Se escucha cada consulta (sin guardar el log completo, que con miles de consultas se vuelve pesado) y se retienen solo las más lentas.
        DB::listen(function ($q) use (&$n, &$sql, &$top, $topN) {
            $n++; $sql += $q->time;
            if (count($top) < $topN || $q->time > end($top)['time']) { $top[] = ['time' => $q->time, 'query' => $q->sql]; usort($top, fn($a, $b) => $b['time'] <=> $a['time']); $top = array_slice($top, 0, $topN); }
        });
        foreach ($this->argument('urls') as $url) {
            Auth::login($u);
            $n = 0; $sql = 0.0; $top = [];
            $req = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
            $req->setUserResolver(fn() => $u);
            $t = microtime(true);
            $res = $kernel->handle($req);
            $ms = (int) round((microtime(true) - $t) * 1000);
            $log = $top; $sql = (int) round($sql);
            $this->line(sprintf('%s %-48s %5d ms · %3d consultas · %5d ms en SQL', $ms > (int) $this->option('umbral') ? '<fg=red>LENTA</>' : '<fg=green>  ok </>', $url . ' (' . $res->getStatusCode() . ')', $ms, $n, $sql));
            foreach (array_slice($log, 0, (int) $this->option('top')) as $q) if ($q['time'] >= 20) $this->line('        ' . str_pad((string) round($q['time']), 5, ' ', STR_PAD_LEFT) . ' ms  ' . preg_replace('/\s+/', ' ', substr($q['query'], 0, 160)));
            if ($ms > (int) $this->option('umbral')) $lentas++;
            $kernel->terminate($req, $res);
        }
        return $lentas ? 1 : 0;
    }
}
