<?php

namespace Tests\Feature;

use App\Models\WhatsappVentana;
use App\Services\Canales\WhatsappPedidosService;
use App\Services\Canales\WhatsappPlantillasService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 120.1: plantillas de WhatsApp aprobadas y ventana de 24 horas.
class Fase120aTest extends ErpTestCase
{
    private const MSG = 'graph.facebook.com/v19.0/123/messages';

    private function conectar(array $plantillas = []): void
    {
        $est = []; foreach ($plantillas as $n => $e) $est[$n] = ['estado' => $e, 'motivo' => null];
        $this->empresa->update(['whatsapp_settings' => array_filter(['token' => 'tok', 'phone_id' => '123', 'waba_id' => 'WABA1', 'verify_token' => 'v', 'plantillas' => $est ?: null])]);
    }

    private function enviados(): array
    {
        $out = [];
        Http::assertSent(function ($r) use (&$out) { if (str_contains($r->url(), '/123/messages')) $out[] = $r->data(); return true; });
        return $out;
    }

    public function test_pide_las_plantillas_que_faltan_y_guarda_el_estado_de_cada_una(): void
    {
        $this->conectar();
        Http::fake(['graph.facebook.com/*/WABA1/message_templates*' => function ($req) {
            if ($req->method() === 'GET') return Http::response(['data' => [['name' => 'bigsys_aviso', 'status' => 'APPROVED', 'language' => 'es_AR'], ['name' => 'bigsys_recordatorio', 'status' => 'REJECTED', 'language' => 'es_AR', 'rejected_reason' => 'INVALID_FORMAT'], ['name' => 'bigsys_aviso', 'status' => 'PENDING', 'language' => 'en_US']]]);
            return Http::response(['id' => '999', 'status' => 'PENDING', 'category' => 'UTILITY']);
        }]);
        $this->post('/configuracion/tienda/whatsapp/plantillas')->assertRedirect()->assertSessionHas('success');
        $e = app(WhatsappPlantillasService::class)->estado($this->empresa->fresh());
        $por = collect($e['plantillas'])->keyBy('nombre');
        $this->assertSame('aprobada', $por['bigsys_aviso']['estado']); $this->assertSame('rechazada', $por['bigsys_recordatorio']['estado']); $this->assertSame('INVALID_FORMAT', $por['bigsys_recordatorio']['motivo']);
        $this->assertSame('en_revision', $por['bigsys_informe']['estado']); $this->assertSame('en_revision', $por['bigsys_encargo']['estado']); $this->assertSame(1, $e['aprobadas']);
        // Pidió sólo las dos que no existían, con el cuerpo, el idioma y el ejemplo que exige Meta.
        Http::assertSentCount(3);
        Http::assertSent(fn($r) => $r->method() === 'POST' && $r['name'] === 'bigsys_encargo' && $r['language'] === 'es_AR' && $r['category'] === 'UTILITY' && str_contains($r['components'][0]['text'], '{{4}}') && count($r['components'][0]['example']['body_text'][0]) === 4);
        $p = $this->get('/configuracion/tienda')->viewData('page')['props']['whatsapp']['plantillas'];
        $this->assertTrue($p['disponible']); $this->assertSame(1, $p['aprobadas']);
    }

    public function test_sin_el_identificador_de_la_cuenta_avisa_y_no_llama_a_meta(): void
    {
        $this->empresa->update(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '123']]); Http::fake();
        $this->post('/configuracion/tienda/whatsapp/plantillas')->assertSessionHasErrors('plantillas');
        Http::assertNothingSent();
        // Cargándolo en «Avanzado» queda disponible.
        $this->postJson('/configuracion/tienda/whatsapp', ['waba_id' => 'WABA9', 'auto_pedidos' => true, 'auto_responder' => true, 'atencion_clientes' => true, 'bigcito_equipo' => true])->assertRedirect();
        $this->assertSame('WABA9', $this->empresa->fresh()->whatsapp_settings['waba_id']); $this->assertSame('123', $this->empresa->fresh()->whatsapp_settings['phone_id']);
    }

    public function test_con_la_ventana_cerrada_sale_la_plantilla_y_el_texto_llega_cuando_contesta(): void
    {
        $this->conectar(['bigsys_aviso' => 'aprobada']); Http::fake([self::MSG => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $wa = app(WhatsappPedidosService::class);
        $r = $wa->responder($this->empresa->fresh(), '5493515551234', "*Tu pedido está listo*\nPasá a retirarlo de 9 a 18.", ['nombre' => 'Ana']);
        $this->assertTrue($r['enviado']); $this->assertSame('plantilla', $r['via']); $this->assertTrue($r['pendiente']);
        $env = $this->enviados();
        $this->assertSame('template', $env[0]['type']); $this->assertSame('bigsys_aviso', $env[0]['template']['name']); $this->assertSame('es_AR', $env[0]['template']['language']['code']);
        $this->assertSame(['Ana', $this->empresa->name, 'Tu pedido está listo'], array_column($env[0]['template']['components'][0]['parameters'], 'text'));
        $this->assertCount(1, WhatsappVentana::first()->pendientes);

        // La persona contesta VER: se abre la ventana, recibe el texto completo y no se dispara nada más.
        Auth::logout();
        $res = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '5493515551234', 'texto' => 'Ver'])->assertOk()->json();
        $this->assertSame(['pendientes'], $res['atendidos']);
        $env = $this->enviados();
        $this->assertSame('text', $env[1]['type']); $this->assertStringContainsString('Pasá a retirarlo de 9 a 18', $env[1]['text']['body']);
        $this->assertNull(WhatsappVentana::first()->pendientes); $this->assertCount(2, $env);

        // Ahora la ventana está abierta: lo siguiente sale como texto normal.
        $r = $wa->responder($this->empresa->fresh(), '+54 9 351 555-1234', 'Gracias por pasar.');
        $this->assertSame('texto', $r['via']); $this->assertSame('text', $this->enviados()[2]['type']);
    }

    public function test_sin_plantillas_aprobadas_todo_sigue_como_antes(): void
    {
        $this->conectar(); Http::fake([self::MSG => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $r = app(WhatsappPedidosService::class)->responder($this->empresa->fresh(), '5493515551234', 'Hola');
        $this->assertTrue($r['enviado']); $this->assertSame('text', $this->enviados()[0]['type']); $this->assertSame(0, WhatsappVentana::count());
    }

    public function test_el_recordatorio_de_deuda_usa_su_plantilla_con_el_saldo(): void
    {
        $this->conectar(['bigsys_aviso' => 'aprobada', 'bigsys_recordatorio' => 'aprobada']); Http::fake([self::MSG => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $c = $this->cliente(['name' => 'Ana Pérez', 'phone' => '3515551234']);
        $f = $this->factura($c, [['descripcion' => 'Cemento', 'cantidad' => 1, 'precio_unit' => 10000, 'alicuota_iva' => 21]]);
        $e = app(\App\Services\Envios\EnvioService::class)->enviar($f, 'whatsapp', $c->phone, 'recordatorio', 'Hola Ana, te recordamos la factura pendiente. Detalle: ...', false);
        $this->assertSame('enviado', $e->estado);
        $env = $this->enviados();
        $this->assertSame('bigsys_recordatorio', $env[0]['template']['name']);
        $this->assertSame(['Ana', $this->empresa->name, '$ 12.100'], array_column($env[0]['template']['components'][0]['parameters'], 'text'));
        $this->assertStringContainsString('Detalle', WhatsappVentana::first()->pendientes[0]['texto']);
    }

    public function test_si_meta_rebota_un_texto_por_la_ventana_se_reintenta_con_plantilla(): void
    {
        $this->conectar(['bigsys_aviso' => 'aprobada']); Http::fake([self::MSG => Http::sequence()->push(['messages' => [['id' => 'wamid.TEXTO']]])->push(['messages' => [['id' => 'wamid.PLANTILLA']]])]);
        // Creíamos que la ventana estaba abierta (escribió hace 23 horas según nosotros).
        WhatsappVentana::create(['business_id' => $this->empresa->id, 'telefono' => '3515551234', 'ultimo_entrante_en' => now()->subHours(23)]);
        $r = app(WhatsappPedidosService::class)->responder($this->empresa->fresh(), '5493515551234', 'Tu orden está lista.', ['nombre' => 'Ana']);
        $this->assertSame('texto', $r['via']); $this->assertSame('wamid.TEXTO', WhatsappVentana::first()->ultimo_saliente['wamid']);

        Auth::logout();
        $aviso = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.TEXTO', 'status' => 'failed', 'recipient_id' => '5493515551234', 'errors' => [['code' => 131047, 'title' => 'Re-engagement message']]]]]]]]]];
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", $aviso)->assertOk()->assertJsonPath('atendidos.0', 'reintento_plantilla');
        $env = $this->enviados();
        $this->assertSame('template', $env[1]['type']); $this->assertSame('Tu orden está lista.', WhatsappVentana::first()->pendientes[0]['texto']); $this->assertNull(WhatsappVentana::first()->ultimo_entrante_en);
        // El mismo aviso repetido no manda nada más.
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", $aviso)->assertOk();
        $this->assertCount(2, $this->enviados());
    }

    public function test_parametros_limpios_y_comando_diario(): void
    {
        $this->assertSame('una línea con espacios', WhatsappPlantillasService::param("una línea\n\tcon     espacios"));
        $this->assertSame('-', WhatsappPlantillasService::param('   ')); $this->assertSame(10, mb_strlen(WhatsappPlantillasService::param(str_repeat('a', 50), 10)));
        $this->assertSame('3515551234', WhatsappPlantillasService::clave('+54 9 351 555-1234')); $this->assertSame('3515551234', WhatsappPlantillasService::clave('0351 555 1234'));
        $this->conectar();
        Http::fake(['graph.facebook.com/*/WABA1/message_templates*' => fn($req) => $req->method() === 'GET' ? Http::response(['data' => collect(WhatsappPlantillasService::CATALOGO)->keys()->map(fn($n) => ['name' => $n, 'status' => 'APPROVED', 'language' => 'es_AR'])->all()]) : Http::response([], 500)]);
        $this->artisan('whatsapp:plantillas')->assertSuccessful();
        $this->assertSame(4, app(WhatsappPlantillasService::class)->estado($this->empresa->fresh())['aprobadas']);
        Http::fake(); $this->artisan('whatsapp:plantillas')->assertSuccessful(); Http::assertNothingSent(); // completas: no vuelve a consultar
    }
}
