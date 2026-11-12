<?php

namespace Tests\Feature;

use App\Mail\ResumenSemanalSistema;
use App\Models\SistemaConfig;
use App\Models\Ticket;
use App\Services\Producto\UsoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 86: el cierre del bloque Intuitivo: medir antes y después, la guía "Qué cambió" y el tour otra vez.
class CierreIntuitivoFase86Test extends ErpTestCase
{
    private function uso(int $userId, string $fecha, string $modulo, int $vistas, int $acciones): void
    {
        DB::table('uso_diario')->insert(['business_id' => $this->empresa->id, 'user_id' => $userId, 'fecha' => $fecha, 'modulo' => $modulo, 'vistas' => $vistas, 'acciones' => $acciones]);
    }

    public function test_la_navegacion_se_mide_contra_la_semana_anterior(): void
    {
        $hoy = today()->toDateString(); $hace10 = today()->subDays(10)->toDateString();
        // Semana anterior: una persona recorre 4 módulos para hacer 2 acciones en 20 vistas.
        foreach (['comprobantes', 'clientes', 'fondos', 'stock'] as $m) $this->uso($this->dueno->id, $hace10, $m, 5, 0);
        DB::table('uso_diario')->where('fecha', $hace10)->where('modulo', 'comprobantes')->update(['acciones' => 2]);
        // Esta semana: 2 módulos, 4 acciones en 10 vistas.
        $this->uso($this->dueno->id, $hoy, 'comprobantes', 6, 3); $this->uso($this->dueno->id, $hoy, 'clientes', 4, 1);
        Ticket::create(['business_id' => $this->empresa->id, 'user_id' => $this->dueno->id, 'asunto' => 'No encuentro los cheques', 'categoria' => 'consulta', 'prioridad' => 'normal', 'estado' => 'abierto', 'mensajes' => []]);
        Ticket::create(['business_id' => $this->empresa->id, 'user_id' => $this->dueno->id, 'asunto' => 'Factura rechazada', 'categoria' => 'error', 'prioridad' => 'alta', 'estado' => 'abierto', 'mensajes' => [['texto' => 'ARCA devuelve error']]]);

        $n = app(UsoService::class)->navegacion(7);
        $this->assertSame(4.0, $n['antes']['pantallas_por_dia']); $this->assertSame(2.0, $n['ahora']['pantallas_por_dia']);
        $this->assertSame(10.0, $n['antes']['acciones_por_100_vistas']); $this->assertSame(40.0, $n['ahora']['acciones_por_100_vistas']);
        $this->assertSame(1, $n['ahora']['tickets_no_encuentro'], 'Sólo el ticket que dice que no encuentra algo'); $this->assertSame(0, $n['antes']['tickets_no_encuentro']);
        $this->assertSame(1, $n['ahora']['usuarios']);
    }

    public function test_el_resumen_semanal_y_el_panel_de_uso_llevan_la_navegacion(): void
    {
        Mail::fake(); SistemaConfig::set('monitoreo_email', 'admin@bigsys.test');
        Artisan::call('sistema:resumen-semanal');
        Mail::assertSent(ResumenSemanalSistema::class, function ($m) {
            $this->assertArrayHasKey('navegacion', $m->r); $this->assertArrayHasKey('pantallas_por_dia', $m->r['navegacion']['ahora']);
            $this->assertStringContainsString('Navegación (semana anterior → esta)', $m->build()->render());
            return true;
        });
        $this->dueno->forceFill(['is_superadmin' => true])->save();
        $p = $this->actingAs($this->dueno->fresh())->get('/admin/uso')->assertOk()->viewData('page')['props'];
        $this->assertArrayHasKey('antes', $p['navegacion']);
    }

    public function test_la_guia_que_cambio_existe_y_las_novedades_del_bloque_llevan_a_ella(): void
    {
        $r = $this->actingAs($this->dueno)->get('/ayuda/que-cambio')->assertOk();
        $a = $r->viewData('page')['props']['articulo'];
        $this->assertStringContainsString('Qué cambió', $a['titulo']);
        foreach (['Vender', 'Plata', 'Comprar', 'Cerrar caja', 'Cheques y valores', 'Vencimientos previstos', 'Pesables y cortes', 'Simulador de precios', 'Un módulo cada uno', 'Qué reponer'] as $x) $this->assertStringContainsString($x, $a['html'] ?? $a['contenido'] ?? json_encode($a));
        foreach (glob(database_path('migrations/2027_01_0*_fase*_novedad_*.php')) as $f) $this->assertStringContainsString("'url' => '/ayuda/que-cambio'", file_get_contents($f), basename($f));
        $this->assertStringContainsString('que-cambio', \App\Mcp\Recursos::instrucciones($this->dueno));
    }

    public function test_el_tour_se_vuelve_a_ofrecer_una_vez(): void
    {
        $m = file_get_contents(database_path('migrations/2027_01_09_000001_fase86_tour_otra_vez.php'));
        $this->assertStringContainsString("update(['tour_visto_en' => null])", $m);
        $this->assertStringContainsString('runningUnitTests', $m, 'En las pruebas no se toca');
        // El tour aparece en Inicio para quien tiene tour_visto_en en null, y su primer paso cuenta el menú nuevo.
        $this->dueno->forceFill(['tour_visto_en' => null])->save();
        $tour = $this->actingAs($this->dueno->fresh())->get('/dashboard')->assertOk()->viewData('page')['props']['tour'];
        $this->assertSame('El menú', $tour[0]['titulo']); $this->assertStringContainsString('Vender, Cobrar, Entregar', $tour[0]['texto']);
    }
}
