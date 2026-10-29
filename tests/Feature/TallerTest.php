<?php

namespace Tests\Feature;

use App\Models\OrdenTrabajo;
use App\Models\OrdenTrabajoItem;
use App\Services\Servicios\OrdenesService;
use App\Services\Servicios\TallerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 36.1, 36.3 y 36.4: qué reparás, qué sigue y esperando; aprobación por ítem que se anula si cambian los importes; técnicos por %, plantillas, fotos, mis órdenes y lo que se le paga a cada uno.
class TallerTest extends ErpTestCase
{
    private function ot(array $extra = []): OrdenTrabajo
    {
        return app(OrdenesService::class)->crear(array_merge(['contact_id' => $this->cliente()->id, 'equipo' => 'Gol Trend', 'serie' => 'AB123CD', 'falla' => 'Ruido al frenar'], $extra));
    }

    public function test_que_reparas_arma_las_palabras_y_que_sigue_y_esperando_con_motivo(): void
    {
        $t = app(TallerService::class);
        $this->assertFalse($t->config()['configurado']);
        $this->post('/servicios/config', ['rubro' => 'taller'])->assertSessionHas('success');
        $c = $t->config(); $this->assertSame('Vehículo', $c['equipo']); $this->assertSame('Patente', $c['serie']); $this->assertSame('Km', $c['medidor']); $this->assertSame('Mecánico', $c['tecnico']); $this->assertContains('chasis', $c['campos']);
        $this->post('/servicios', ['contact_id' => $this->cliente(['name' => 'Ana', 'cuit' => null])->id, 'equipo' => 'Gol Trend', 'serie' => 'AB123CD', 'falla' => 'Ruido', 'medidor' => 85000, 'datos_equipo' => ['marca' => 'VW', 'año' => '2015']])->assertSessionHasNoErrors();
        $o = OrdenTrabajo::latest('id')->first(); $this->assertSame('VW', $o->datos_equipo['marca']); $this->assertEquals(85000, $o->medidor);
        $this->assertSame('Diagnosticar', $t->queSigue($o)['paso']);
        $o->update(['diagnostico' => 'Pastillas gastadas']); $this->assertSame('Cargar los repuestos y la mano de obra', $t->queSigue($o->fresh())['paso']);
        $this->post("/servicios/{$o->id}/esperar", ['motivo' => 'Repuesto pedido a Buenos Aires'])->assertSessionHas('success');
        $o->refresh(); $this->assertSame('esperando', $o->estado); $this->assertStringContainsString('Esperando: Repuesto pedido', $t->queSigue($o)['paso']);
        $this->post("/servicios/{$o->id}/estado", ['estado' => 'esperando'])->assertStatus(422);
        $this->post("/servicios/{$o->id}/estado", ['estado' => 'en_curso']); $this->assertNull($o->fresh()->esperando_motivo);
        $this->get('/servicios')->assertInertia(fn($p) => $p->component('Servicios/Index', false)->where('config.equipo', 'Vehículo'));
    }

    public function test_aprobacion_por_item_lo_no_aprobado_queda_para_la_proxima_y_un_cambio_anula(): void
    {
        $svc = app(OrdenesService::class); $t = app(TallerService::class);
        $o = $this->ot();
        $a = $svc->item($o, ['descripcion' => 'Pastillas de freno', 'cantidad' => 1, 'precio_unit' => 30000, 'tipo' => 'material']);
        $b = $svc->item($o, ['descripcion' => 'Mano de obra frenos', 'cantidad' => 1, 'precio_unit' => 20000, 'tipo' => 'mano_obra']);
        $c = $svc->item($o, ['descripcion' => 'Cambio de correa', 'cantidad' => 1, 'precio_unit' => 90000, 'tipo' => 'mano_obra', 'actualizar_presupuesto' => true]);
        $svc->estado($o->fresh(), 'presupuestado');
        // El cliente destilda la correa desde el link
        $this->post("/ot/{$o->token}", ['respuesta' => 'aprobar', 'nombre' => 'Ana', 'aprobados' => [$a->id, $b->id]])->assertRedirect();
        $o->refresh(); $this->assertSame('aprobado', $o->estado); $this->assertEquals(50000, $o->presupuesto); $this->assertSame([$a->id, $b->id], $o->aprobacion['items']);
        $this->assertTrue($c->fresh()->pendiente_proxima); $this->assertFalse($c->fresh()->aprobado);
        // Destildar todo desde el link no aprueba nada
        $o3 = $this->ot(['serie' => 'NADA1']); $svc->item($o3, ['descripcion' => 'X', 'cantidad' => 1, 'precio_unit' => 100]); $svc->estado($o3->fresh(), 'presupuestado');
        $this->post("/ot/{$o3->token}", ['respuesta' => 'aprobar', 'nombre' => 'Ana', 'por_item' => 1])->assertStatus(422); $this->assertSame('presupuestado', $o3->fresh()->estado);
        // Un cambio de importes anula la aprobación
        $r = $this->post("/servicios/{$o->id}/items", ['descripcion' => 'Líquido de frenos', 'cantidad' => 1, 'precio_unit' => 5000]);
        $r->assertSessionHas('error'); $o->refresh(); $this->assertSame('presupuestado', $o->estado); $this->assertNull($o->aprobacion); $this->assertNull($a->fresh()->aprobado);
        // Aprobar de nuevo y facturar: sólo lo aprobado
        $this->post("/ot/{$o->token}", ['respuesta' => 'aprobar', 'nombre' => 'Ana', 'aprobados' => [$a->id, $b->id]]);
        $fac = $svc->facturar($o->fresh());
        $this->assertEqualsWithDelta(50000, (float) $fac->total, 0.05, 'la correa y el líquido no aprobados no se facturan');
        // Próxima visita del mismo auto: aparece lo pendiente y se trae con un clic
        $o2 = $this->ot(['falla' => 'Service']);
        $pend = $t->pendientesAnteriores($o2); $this->assertCount(2, $pend); $this->assertContains('Cambio de correa', array_column($pend, 'descripcion'));
        $this->post("/servicios/{$o2->id}/pendientes/{$c->id}")->assertSessionHas('success');
        $this->assertSame('Cambio de correa', $o2->items()->first()->descripcion); $this->assertFalse($c->fresh()->pendiente_proxima);
        $this->assertCount(1, $t->pendientesAnteriores($o2->fresh()));
    }

    public function test_tecnicos_por_porcentaje_plantillas_fotos_mis_ordenes_y_lo_que_se_paga(): void
    {
        Storage::fake();
        $svc = app(OrdenesService::class); $t = app(TallerService::class);
        $juan = $this->usuarioConRol('tecnico', 'juan@test.com'); $pedro = $this->usuarioConRol('tecnico', 'pedro@test.com');
        $this->post('/servicios/config', ['rubro' => 'taller', 'pago_tecnico_pct' => 50]);
        $o = $this->ot(['tecnico_id' => $juan->id]);
        $mo = $svc->item($o, ['descripcion' => 'Mano de obra motor', 'cantidad' => 1, 'precio_unit' => 100000, 'tipo' => 'mano_obra']);
        $mo2 = $svc->item($o, ['descripcion' => 'Alineación', 'cantidad' => 1, 'precio_unit' => 10000, 'tipo' => 'mano_obra']);
        $svc->item($o, ['descripcion' => 'Aceite', 'cantidad' => 4, 'precio_unit' => 5000, 'tipo' => 'material']);
        $this->post("/servicios/{$o->id}/items/{$mo->id}/reparto", ['reparto' => [['user_id' => $juan->id, 'pct' => 70], ['user_id' => $pedro->id, 'pct' => 20]]])->assertSessionHasErrors('reparto');
        $this->post("/servicios/{$o->id}/items/{$mo->id}/reparto", ['reparto' => [['user_id' => $juan->id, 'pct' => 70], ['user_id' => $pedro->id, 'pct' => 30]]])->assertSessionHas('success');
        // Mis órdenes: Pedro la ve porque tiene parte en la mano de obra
        $this->assertCount(1, $t->misOrdenes($pedro)); $this->assertCount(1, $t->misOrdenes($juan));
        // Plantilla
        $this->post("/servicios/{$o->id}/plantilla", ['nombre' => 'Service completo'])->assertSessionHas('success');
        $o2 = $this->ot(['serie' => 'ZZ999ZZ']); $pl = \App\Models\ServicioPlantilla::first();
        $this->post("/servicios/{$o2->id}/plantilla/{$pl->id}")->assertSessionHas('success'); $this->assertSame(3, $o2->items()->count()); $this->assertEquals(130000, $o2->fresh()->presupuesto);
        // Fotos antes/durante/después
        $this->post("/servicios/{$o->id}/fotos", ['momento' => 'antes', 'fotos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->assertSessionHas('success');
        $f = $o->fotos()->first(); $this->assertSame('antes', $f->momento); $this->get("/servicios/{$o->id}/fotos/{$f->id}")->assertOk();
        // Entregar y pagar: 100.000 × (70 % Juan, 30 % Pedro) + alineación 10.000 al técnico de la orden (Juan); pago 50 %
        $svc->facturar($o->fresh());
        $p = collect($t->pagoTecnicos(today()->toDateString(), today()->toDateString())['tecnicos'])->keyBy('user_id');
        $this->assertEquals(80000, $p[$juan->id]['mano_obra']); $this->assertEquals(40000, $p[$juan->id]['a_pagar']);
        $this->assertEquals(30000, $p[$pedro->id]['mano_obra']); $this->assertEquals(15000, $p[$pedro->id]['a_pagar']);
        $this->get('/servicios/tecnicos')->assertOk()->assertInertia(fn($pg) => $pg->component('Servicios/Tecnicos', false)->has('pago.tecnicos', 2));
        if ($this->empresa->fresh()->tieneModulo('servicios')) $this->actingAs($pedro)->get('/servicios/mis-ordenes')->assertOk()->assertInertia(fn($pg) => $pg->has('ordenes', 0)); // ya entregada: no aparece
        $this->actingAs($this->dueno)->get("/servicios/{$o->id}")->assertOk()->assertInertia(fn($pg) => $pg->component('Servicios/Ver', false)->has('que_sigue')->has('fotos.antes', 2));
    }
}
