<?php

namespace App\Services\Monitoreo;

use App\Models\Business;
use App\Models\MedicionPagina;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Fase 77: mide páginas desde adentro (sin navegador): tiempo, cantidad de consultas y las más lentas. A mano (erp:medir) o cada noche
// sobre las empresas más grandes, guardando la historia para que la salud avise cuando algo se pone lento.
class MedicionService
{
    public const PAGINAS = ['/dashboard', '/comprobantes', '/clientes', '/stock', '/estadisticas', '/fondos', '/alertas'];
    public const UMBRAL_MS = 300;   // por encima, la página es "lenta"
    public const EMPRESAS = 15;     // cuántas empresas se miden por noche (las más grandes por comprobantes)

    public function __construct(private Kernel $kernel) {}

    public static function umbral(): int { return (int) (SistemaConfig::get('umbral_ms') ?: self::UMBRAL_MS); }

    // Mide las URLs como el usuario dado. Devuelve [url => ['ms','consultas','sql_ms','http','lentas']].
    public function medir(array $urls, User $u, int $top = 3): array
    {
        $n = 0; $sql = 0.0; $lentas = []; $out = [];
        $oyente = function ($q) use (&$n, &$sql, &$lentas, $top) {
            $n++; $sql += $q->time;
            if (count($lentas) < $top || $q->time > end($lentas)['ms']) { $lentas[] = ['ms' => round($q->time), 'sql' => mb_substr($q->sql, 0, 300)]; usort($lentas, fn($a, $b) => $b['ms'] <=> $a['ms']); $lentas = array_slice($lentas, 0, $top); }
        };
        DB::listen($oyente);
        try {
            foreach ($urls as $url) {
                Auth::login($u); $n = 0; $sql = 0.0; $lentas = [];
                $req = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']); $req->setUserResolver(fn() => $u);
                $t = microtime(true);
                try { $res = $this->kernel->handle($req); $http = $res->getStatusCode(); $this->kernel->terminate($req, $res); } catch (\Throwable $e) { $http = 500; }
                $out[$url] = ['ms' => (int) round((microtime(true) - $t) * 1000), 'consultas' => $n, 'sql_ms' => (int) round($sql), 'http' => $http, 'lentas' => array_values(array_filter($lentas, fn($l) => $l['ms'] >= 20))];
                Auth::logout();
            }
        } finally { Auth::logout(); }
        return $out;
    }

    // La ronda nocturna: las empresas más grandes, con su dueño, en las páginas clave. Guarda y limpia lo de más de 60 días.
    public function nocturna(?array $urls = null, ?int $empresas = null): array
    {
        $ronda = now()->format('Y-m-d H:i'); $urls ??= self::PAGINAS; $res = [];
        $grandes = Business::where('is_active', true)->whereNull('suspended_at')->withCount('comprobantesVenta')->orderByDesc('comprobantes_venta_count')->limit($empresas ?? self::EMPRESAS)->get();
        foreach ($grandes as $b) {
            $u = User::withoutGlobalScopes()->where('business_id', $b->id)->where('status', 'active')->where('is_superadmin', false)->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$b->owner_id])->orderBy('id')->first();
            if (! $u) continue;
            foreach ($this->medir($urls, $u) as $url => $m) {
                MedicionPagina::create(['business_id' => $b->id, 'url' => $url, 'ms' => $m['ms'], 'consultas' => $m['consultas'], 'sql_ms' => $m['sql_ms'], 'http' => $m['http'], 'lentas' => $m['lentas'], 'ronda' => $ronda, 'created_at' => now()]);
                $res[] = ['empresa' => $b->name, 'url' => $url] + $m;
            }
        }
        MedicionPagina::where('created_at', '<', now()->subDays(60))->delete();
        return $res;
    }

    // Resumen de la última ronda por página: el peor tiempo, dónde y cuántas consultas; y si venía lenta la noche anterior también.
    public function ultimaRonda(): array
    {
        $ronda = MedicionPagina::max('ronda'); if (! $ronda) return ['ronda' => null, 'paginas' => [], 'lentas' => 0, 'lentas_dos_noches' => 0, 'umbral' => self::umbral()];
        $anterior = MedicionPagina::where('ronda', '<', $ronda)->max('ronda');
        $filas = MedicionPagina::where('ronda', $ronda)->get()->groupBy('url')->map(function ($g, $url) use ($anterior) {
            $peor = $g->sortByDesc('ms')->first(); $prev = $anterior ? MedicionPagina::where('ronda', $anterior)->where('url', $url)->max('ms') : null;
            return ['url' => $url, 'peor_ms' => (int) $peor->ms, 'promedio_ms' => (int) round($g->avg('ms')), 'consultas' => (int) $peor->consultas, 'sql_ms' => (int) $peor->sql_ms, 'empresa' => $peor->business_id, 'errores' => $g->where('http', '>=', 500)->count(),
                'anterior_ms' => $prev !== null ? (int) $prev : null, 'lentas' => $peor->lentas ?? []];
        })->sortByDesc('peor_ms')->values();
        $u = self::umbral();
        return ['ronda' => $ronda, 'umbral' => $u, 'paginas' => $filas->all(), 'lentas' => $filas->where('peor_ms', '>', $u)->count(),
            'lentas_dos_noches' => $filas->filter(fn($f) => $f['peor_ms'] > $u && $f['anterior_ms'] !== null && $f['anterior_ms'] > $u)->count(), 'con_error' => $filas->sum('errores')];
    }
}
