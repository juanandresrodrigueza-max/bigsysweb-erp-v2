<?php

namespace Tests\Feature;

use App\Models\OrdenTrabajo;
use App\Services\Servicios\OrdenesService;
use App\Services\Servicios\TallerHistoriaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 36.2, 36.5 y 36.6: recepción a mano y con IA, comparación con la visita anterior y acta; que vuelva por tiempo, medidor y pieza;
// línea de vida, flota, cambio de dueño, remito y para revisar.
class TallerHistoriaTest extends ErpTestCase
{
    private function ot(array $extra = []): OrdenTrabajo
    {
        return app(OrdenesService::class)->crear(array_merge(['contact_id' => $this->cliente(['phone' => '3511234567'])->id, 'equipo' => 'Gol Trend', 'serie' => 'AB123CD', 'falla' => 'Service'], $extra));
    }

    public function test_recepcion_a_mano_con_ia_comparacion_y_acta(): void
    {
        Storage::fake();
        $this->post('/servicios/config', ['rubro' => 'taller']);
        $ant = $this->ot(['fecha_ingreso' => today()->subDays(100)->toDateString(), 'medidor' => 80000]);
        $this->post("/servicios/{$ant->id}/recepcion", ['danios' => [['zona' => 'Paragolpe trasero', 'detalle' => 'Raspón']]])->assertSessionHas('success');
        $o = $this->ot();
        // A mano
        $this->post("/servicios/{$o->id}/recepcion", ['medidor' => 85000, 'combustible' => '1/2', 'checklist' => ['Frenos' => 'rojo', 'Luces' => 'verde'], 'danios' => [['zona' => 'Paragolpe trasero', 'detalle' => 'Raspón'], ['zona' => 'Puerta izquierda', 'detalle' => 'Abollón']]])->assertSessionHas('success');
        $r = $o->fresh()->recepcion; $this->assertSame('rojo', $r['checklist']['Frenos']); $this->assertEquals(85000, $o->fresh()->medidor);
        $this->assertEquals(5000, $r['comparacion']['anduvo']); $this->assertEquals(50, $r['comparacion']['por_dia']); $this->assertSame(['Puerta izquierda'], $r['comparacion']['danios_nuevos']);
        // Con IA: sin clave avisa; con clave completa desde las fotos
        $this->post("/servicios/{$o->id}/fotos", ['momento' => 'recepcion', 'fotos' => [UploadedFile::fake()->image('tablero.jpg')]]);
        $this->post("/servicios/{$o->id}/recepcion/ia")->assertStatus(422); // sin clave de IA de la empresa
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->actingAs($this->dueno->fresh());
        $foto = $o->fotos()->first();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['medidor' => 85210, 'combustible' => '1/4', 'luces' => ['Check engine'], 'patente_leida' => 'AB123CD', 'danios' => [['zona' => 'Faro delantero', 'detalle' => 'Roto', 'foto_id' => $foto->id]], 'checklist' => ['Frenos' => 'amarillo', 'Inventado' => 'rojo'], 'sugeridos' => ['Cambio de pastillas']])]]])]);
        $this->post("/servicios/{$o->id}/recepcion/ia")->assertSessionHas('success');
        $r = $o->fresh()->recepcion;
        $this->assertEquals(85210, $o->fresh()->medidor); $this->assertSame(['Check engine'], $r['luces']); $this->assertSame($foto->id, $r['danios'][0]['foto_id']);
        $this->assertArrayNotHasKey('Inventado', $r['checklist']); $this->assertSame(['Cambio de pastillas'], $r['sugeridos']); $this->assertTrue($r['ia']);
        // Acta PDF
        $this->get("/servicios/{$o->id}/acta")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_que_vuelva_por_tiempo_por_medidor_estimado_y_por_pieza(): void
    {
        $svc = app(OrdenesService::class); $h = app(TallerHistoriaService::class);
        $this->post('/servicios/config', ['rubro' => 'taller']);
        $this->post('/servicios/piezas', ['piezas' => [['nombre' => 'Correa', 'cada' => 60000]]])->assertSessionHas('success');
        // Dos visitas: 50 km por día
        $a = $this->ot(['fecha_ingreso' => today()->subDays(400)->toDateString(), 'medidor' => 20000]);
        $svc->item($a, ['descripcion' => 'Cambio de correa', 'cantidad' => 1, 'precio_unit' => 1000, 'tipo' => 'mano_obra']); $svc->facturar($a->fresh());
        $b = $this->ot(['fecha_ingreso' => today()->subDays(200)->toDateString(), 'medidor' => 30000]);
        $svc->item($b, ['descripcion' => 'Aceite', 'cantidad' => 1, 'precio_unit' => 1000]); $svc->facturar($b->fresh());
        $this->assertEquals(50, $h->usoDiario('AB123CD'));
        // Programado por medidor: a los 40.000 → 30.000 + 50/día → llega a los 200 días = hoy
        $this->post("/servicios/{$b->id}/vuelta", ['medidor' => 40000])->assertSessionHas('success');
        $l = $h->queVuelvan(30); $this->assertCount(1, $l);
        $this->assertStringContainsString('llega a 40.000 Km', $l[0]['motivos'][0]);
        $this->assertStringContainsString('wa.me', $l[0]['whatsapp']);
        // Correa: cambiada a los 20.000, toca a los 80.000; hoy anda por 40.000 → todavía no
        $this->assertCount(1, $l[0]['motivos']);
        // Con la correa cada 20.000 sí toca (20.000 + 20.000 = 40.000)
        $this->post('/servicios/piezas', ['piezas' => [['nombre' => 'Correa', 'cada' => 20000]]]);
        $l = $h->queVuelvan(30); $this->assertStringContainsString('Correa: toca a los 40.000', implode(' ', $l[0]['motivos']));
        // Por tiempo, otro equipo
        $c = $this->ot(['serie' => 'ZZ1', 'fecha_ingreso' => today()->subMonths(6)->toDateString()]); $svc->facturar($c->fresh());
        $this->post("/servicios/{$c->id}/vuelta", ['meses' => 6]);
        $this->assertContains('ZZ1', array_column($h->queVuelvan(30), 'serie'));
        $this->post("/servicios/{$c->id}/recordado"); $this->assertNotNull($c->fresh()->recordado_en);
        $this->get('/servicios/que-vuelvan')->assertOk()->assertInertia(fn($p) => $p->component('Servicios/QueVuelvan', false)->has('lista', 2));
    }

    public function test_linea_de_vida_flota_cambio_de_dueno_remito_y_para_revisar(): void
    {
        $svc = app(OrdenesService::class); $h = app(TallerHistoriaService::class);
        $cli = $this->cliente(['name' => 'Flota SA']);
        $a = $this->ot(['contact_id' => $cli->id, 'fecha_ingreso' => today()->subDays(60)->toDateString(), 'medidor' => 10000]);
        $svc->item($a, ['descripcion' => 'Service', 'cantidad' => 1, 'precio_unit' => 5000]);
        $rem = $svc->facturar($a->fresh(), 'cta_cte', 'REM'); $this->assertSame('REM', $rem->tipo);
        $this->ot(['contact_id' => $cli->id, 'serie' => 'OTRO1', 'equipo' => 'Kangoo']);
        $b = $this->ot(['contact_id' => $cli->id, 'medidor' => 15000]);
        $vida = $h->vidaEquipo($b); $this->assertCount(2, $vida); $this->assertTrue($vida[0]['actual']); $this->assertSame(['Service'], $vida[1]['trabajos']);
        $flota = $h->flota($cli->id); $this->assertCount(2, $flota); $this->assertSame(2, collect($flota)->firstWhere('serie', 'AB123CD')['visitas']);
        // Cambio de dueño
        $nuevo = $this->cliente(['name' => 'Comprador', 'cuit' => null]);
        $this->post("/servicios/{$b->id}/dueno", ['contact_id' => $nuevo->id])->assertSessionHas('success');
        $this->assertSame($nuevo->id, $b->fresh()->contact_id); $this->assertSame($cli->id, $a->fresh()->contact_id, 'la historia queda');
        $this->assertStringContainsString('Cambio de dueño', $b->fresh()->notas);
        // Para revisar
        $l = $this->ot(['serie' => 'L1']); $l->update(['estado' => 'listo']); OrdenTrabajo::where('id', $l->id)->update(['updated_at' => now()->subDays(9)]);
        $p = $this->ot(['serie' => 'P1']); $p->update(['estado' => 'presupuestado']); OrdenTrabajo::where('id', $p->id)->update(['updated_at' => now()->subDays(4)]);
        $e = $this->ot(['serie' => 'E1']); $e->update(['estado' => 'entregado']);
        $rev = collect($h->paraRevisar())->keyBy('id');
        $this->assertStringContainsString('nadie la retiró', $rev[$l->id]['motivo']); $this->assertStringContainsString('no respondió', $rev[$p->id]['motivo']); $this->assertSame('Entregada sin factura ni remito', $rev[$e->id]['motivo']);
        $this->assertArrayNotHasKey($a->id, $rev->all());
        $this->get("/servicios/{$b->id}")->assertInertia(fn($pg) => $pg->component('Servicios/Ver', false)->has('vida', 2)->has('flota'));
        $this->get('/servicios')->assertInertia(fn($pg) => $pg->has('para_revisar', 3));
    }
}
