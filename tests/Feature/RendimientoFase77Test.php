<?php

namespace Tests\Feature;

use App\Models\MedicionPagina;
use App\Models\SistemaConfig;
use App\Services\Comprobantes\CobroService;
use App\Services\Monitoreo\MedicionService;
use App\Services\Monitoreo\SaludService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

// Fase 77: las pantallas pesadas no hacen una consulta por fila (N+1). Con un dataset mediano, la cantidad de consultas tiene un techo fijo.
class RendimientoFase77Test extends ErpTestCase
{
    private function dataset(): void
    {
        $arts = []; for ($i = 1; $i <= 40; $i++) $arts[] = $this->articulo(['name' => "Artículo {$i}", 'sku' => "A{$i}", 'price' => 100 + $i, 'stock_inicial' => 50]);
        $clientes = []; for ($i = 1; $i <= 30; $i++) $clientes[] = $this->cliente(['name' => "Cliente {$i}", 'phone' => "351000{$i}"]);
        $cobros = app(CobroService::class);
        for ($i = 0; $i < 60; $i++) {
            $c = $clientes[$i % 30]; $a = $arts[$i % 40];
            $f = $this->factura($c, [['product_id' => $a->id, 'cantidad' => 1 + $i % 5, 'precio_unit' => (float) $a->price], ['product_id' => $arts[($i + 7) % 40]->id, 'cantidad' => 2, 'precio_unit' => 90]], ['fecha' => today()->subDays($i % 28)->toDateString()]);
            if ($i % 3 === 0) $cobros->registrar($c, ['medios' => [['medio' => 'efectivo', 'monto' => 100, 'cuenta_fondos_id' => $this->caja->id]], 'imputaciones' => [['comprobante_id' => $f->id, 'monto' => 100]]]);
        }
    }

    private function medir(string $url): array
    {
        DB::flushQueryLog(); DB::enableQueryLog(); $t = microtime(true);
        $r = $this->get($url); $ms = (int) ((microtime(true) - $t) * 1000);
        $log = DB::getQueryLog(); DB::disableQueryLog();
        $r->assertOk();
        // Las consultas repetidas (misma SQL con distintos ids) son la huella del N+1.
        $formas = collect($log)->map(fn($q) => preg_replace('/\d+/', '?', $q['query']))->countBy()->sortDesc();
        return ['n' => count($log), 'ms' => $ms, 'repetidas' => $formas->filter(fn($c) => $c >= 8)->all()];
    }

    public function test_las_pantallas_pesadas_tienen_un_techo_de_consultas(): void
    {
        $this->dataset();
        $this->actingAs($this->dueno->fresh());
        $techos = ['/dashboard' => 60, '/comprobantes' => 40, '/clientes' => 30, '/stock' => 40, '/estadisticas' => 70, '/fondos' => 40, '/alertas' => 30, '/proveedores' => 30, '/clientes/cobranzas' => 50];
        $informe = [];
        foreach ($techos as $url => $max) { $m = $this->medir($url); $informe[$url] = $m; }
        fwrite(STDERR, "\nRENDIMIENTO " . json_encode(array_map(fn($m) => ['n' => $m['n'], 'ms' => $m['ms'], 'rep' => array_slice($m['repetidas'], 0, 2, true)], $informe), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        foreach ($techos as $url => $max) $this->assertLessThanOrEqual($max, $informe[$url]['n'], "{$url}: {$informe[$url]['n']} consultas (techo {$max}). Repetidas: " . json_encode(array_slice($informe[$url]['repetidas'], 0, 3, true)));
    }

    public function test_la_medicion_nocturna_guarda_la_historia_y_la_salud_avisa_cuando_una_pagina_viene_lenta(): void
    {
        $this->articulo(); $this->cliente();
        Artisan::call('erp:medir', ['--nocturna' => true, 'urls' => ['/dashboard', '/clientes']]);
        $this->assertSame(2, MedicionPagina::count()); $m = MedicionPagina::where('url', '/clientes')->first();
        $this->assertSame($this->empresa->id, $m->business_id); $this->assertSame(200, $m->http); $this->assertGreaterThan(0, $m->consultas); $this->assertNotEmpty($m->ronda);
        $this->assertStringContainsString('2 mediciones guardadas', Artisan::output());

        $this->actingAs($this->dueno);
        $r = app(MedicionService::class)->ultimaRonda();
        $this->assertCount(2, $r['paginas']); $this->assertSame(0, $r['lentas']); $this->assertNull($r['paginas'][0]['anterior_ms'], 'Primera noche: sin anterior');
        $ctrl = collect(app(SaludService::class)->verificar()['controles'])->firstWhere('key', 'paginas');
        $this->assertSame('ok', $ctrl['estado']); $this->assertStringContainsString('Todas por debajo', $ctrl['detalle']);

        // Dos noches seguidas con /estadisticas lenta → crítico; una sola → aviso.
        MedicionPagina::create(['business_id' => $this->empresa->id, 'url' => '/estadisticas', 'ms' => 900, 'consultas' => 50, 'sql_ms' => 700, 'http' => 200, 'lentas' => [['ms' => 650, 'sql' => 'select sum(...)']], 'ronda' => MedicionPagina::max('ronda'), 'created_at' => now()]); // en la ronda de "anoche"
        $hoy = now()->addMinute()->format('Y-m-d H:i');
        MedicionPagina::create(['business_id' => $this->empresa->id, 'url' => '/estadisticas', 'ms' => 950, 'consultas' => 50, 'sql_ms' => 700, 'http' => 200, 'lentas' => [], 'ronda' => $hoy, 'created_at' => now()]);
        MedicionPagina::create(['business_id' => $this->empresa->id, 'url' => '/clientes', 'ms' => 40, 'consultas' => 20, 'sql_ms' => 10, 'http' => 200, 'lentas' => [], 'ronda' => $hoy, 'created_at' => now()]);
        $r = app(MedicionService::class)->ultimaRonda();
        $this->assertSame($hoy, $r['ronda']); $this->assertSame(1, $r['lentas']); $this->assertSame(1, $r['lentas_dos_noches']); $this->assertSame('/estadisticas', $r['paginas'][0]['url']); $this->assertSame(900, $r['paginas'][0]['anterior_ms']);
        $ctrl = collect(app(SaludService::class)->verificar()['controles'])->firstWhere('key', 'paginas');
        $this->assertSame('critico', $ctrl['estado']); $this->assertStringContainsString('dos noches seguidas', $ctrl['detalle']);
        // El umbral se puede subir desde la configuración del sistema.
        SistemaConfig::set('umbral_ms', 2000);
        $this->assertSame('ok', collect(app(SaludService::class)->verificar()['controles'])->firstWhere('key', 'paginas')['estado']);
        // El panel del superadmin la muestra.
        $this->dueno->forceFill(['is_superadmin' => true])->save();
        $props = $this->actingAs($this->dueno->fresh())->get('/admin/salud')->assertOk()->viewData('page')['props'];
        $this->assertSame('/estadisticas', $props['paginas']['paginas'][0]['url']);
    }
}
