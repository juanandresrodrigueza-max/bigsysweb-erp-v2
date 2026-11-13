<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Booking;
use App\Models\Comanda;
use App\Services\Gastronomia\DeliveryService;
use App\Services\Gastronomia\ReservasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 91: reservas por WhatsApp con lista de espera, zonas de entrega con costo y la comanda de delivery al reparto.
class ReservasDeliveryFase91Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'tienda' => array_merge($this->empresa->tienda ?? [], ['reservas_capacidad' => 10]),
            'whatsapp_settings' => ['token' => 'tok', 'phone_id' => '1', 'auto_pedidos' => true, 'auto_responder' => true]])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
    }

    public function test_entiende_el_pedido_de_reserva_en_palabras(): void
    {
        $ahora = Carbon::parse('2026-11-12 10:00'); // jueves
        $i = ReservasService::interpretar('Hola! quiero reservar para 4 el sábado a las 21, soy Juan Pérez', $ahora);
        $this->assertSame(4, $i['personas']); $this->assertSame('2026-11-14 21:00', $i['inicio']->format('Y-m-d H:i')); $this->assertSame('Juan Pérez', $i['nombre']); $this->assertFalse($i['pide_datos']);
        $i = ReservasService::interpretar('tienen lugar mañana a las 9 para dos personas?', $ahora);
        $this->assertSame(2, $i['personas']); $this->assertSame('2026-11-13 21:00', $i['inicio']->format('Y-m-d H:i'), '"a las 9" en un restaurante es la noche');
        $i = ReservasService::interpretar('una mesa para 6 el 20/11 al mediodía', $ahora);
        $this->assertSame(6, $i['personas']); $this->assertSame('2026-11-20 13:00', $i['inicio']->format('Y-m-d H:i'));
        $i = ReservasService::interpretar('somos 3, hoy 20:30 hs', $ahora);
        $this->assertSame('2026-11-12 20:30', $i['inicio']->format('Y-m-d H:i'));
        $this->assertTrue(ReservasService::interpretar('quiero reservar', $ahora)['pide_datos']);
        $this->assertTrue(ReservasService::esReserva('Hay lugar para 2 esta noche?')); $this->assertFalse(ReservasService::esReserva('una pizza grande de muzza a Colón 1234'));
    }

    public function test_por_whatsapp_reserva_si_hay_lugar_y_anota_en_espera_si_no(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 10:00'));
        auth()->logout();
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '3515550001', 'texto' => 'Quiero reservar para 6 el sábado a las 21, soy Ana'])->assertOk();
        $this->assertSame(['reserva'], $r->json('atendidos'));
        $ana = Booking::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('confirmed', $ana->status); $this->assertSame(6, (int) $ana->personas); $this->assertSame('Ana', $ana->nombre); $this->assertSame('whatsapp', $ana->origen); $this->assertSame('2026-11-14 21:00', $ana->starts_at->format('Y-m-d H:i'));
        Http::assertSent(fn($q) => str_contains($q->url(), 'graph.facebook.com') && str_contains($q->body(), 'Mesa para 6'));
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'reserva_nueva')->where('modelo_id', $ana->id)->exists());
        // Capacidad 10: para 5 más a la misma hora no hay lugar → lista de espera.
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '3515550002', 'texto' => 'Hola, mesa para 5 el sabado 21hs a nombre de Beto'])->assertOk();
        $beto = Booking::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('waitlist', $beto->status); $this->assertSame('Beto', $beto->nombre);
        Http::assertSent(fn($q) => str_contains($q->body(), 'lista de espera'));
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'reserva_espera')->exists());
        // Si falta el dato, pregunta y no crea nada.
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '3515550003', 'texto' => 'quiero reservar una mesa'])->assertOk();
        $this->assertSame(2, Booking::withoutGlobalScopes()->count());
        // La pantalla muestra la espera; el encargado cancela a Ana → Beto pasa solo y se le avisa.
        $p = $this->actingAs($this->dueno)->get('/gastronomia/reservas')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $p['kpis']['espera']); $this->assertSame('Beto', $p['espera'][0]['nombre']); $this->assertFalse($p['espera'][0]['hay_lugar']); $this->assertSame(10, $p['capacidad']);
        $this->post("/gastronomia/reservas/{$ana->id}/estado", ['estado' => 'cancelled'])->assertSessionHas('success', fn($m) => str_contains($m, 'Beto'));
        $this->assertSame('confirmed', $beto->fresh()->status);
        Http::assertSent(fn($q) => str_contains(json_decode($q->body(), true)['text']['body'] ?? '', 'Se liberó lugar') && str_contains($q->body(), 'Beto'));
        // Bigcito reserva por voz con la misma lógica.
        $this->assertStringContainsString("'reservar_mesa'", file_get_contents(app_path('Mcp/Modulos/Gestion.php')));
    }

    public function test_el_envio_por_zona_se_suma_solo_a_la_comanda_y_se_cobra_al_entregar(): void
    {
        $this->post('/gastronomia/delivery/zonas', ['zonas' => [['nombre' => 'Centro', 'costo' => 800, 'palabras' => 'centro, colón, san martín', 'demora' => 25], ['nombre' => 'Güemes', 'costo' => 1200, 'palabras' => 'guemes, achaval', 'demora' => 40]], 'repartidores' => ['Pepe', ' Lucas ', '']])->assertSessionHas('success');
        $b = $this->empresa->fresh(); $this->assertCount(2, DeliveryService::zonas($b)); $this->assertSame(['Pepe', 'Lucas'], DeliveryService::repartidores($b));
        $this->assertSame('Güemes', DeliveryService::detectar($b, 'Achával 250, Guemes')['nombre']); $this->assertNull(DeliveryService::detectar($b, 'Ruta 9 km 12'));
        $pizza = $this->articulo(['name' => 'Pizza', 'price' => 10000, 'iva' => 0]);
        // Abrir un delivery: la zona se deduce de la dirección y queda como renglón de envío.
        $this->post('/gastronomia/comandas', ['tipo' => 'delivery', 'cliente' => 'Marta', 'direccion' => 'Colón 1234', 'telefono' => '3515550000'])->assertRedirect();
        $c = Comanda::latest('id')->first();
        $this->assertSame('Centro', $c->zona); $this->assertSame(800.0, (float) $c->costo_envio); $this->assertEqualsWithDelta(800, (float) $c->total, 0.01);
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $pizza->id])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(10800, (float) $c->fresh()->total, 0.01);
        // Cambiar la zona reemplaza el renglón; sin zona lo quita.
        $this->post("/gastronomia/comandas/{$c->id}/zona", ['zona' => 'Güemes'])->assertSessionHas('success');
        $c->refresh(); $this->assertEqualsWithDelta(11200, (float) $c->total, 0.01); $this->assertSame(1, $c->items()->whereNull('product_id')->count());
        $p = $this->get("/gastronomia/comandas/{$c->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame('Güemes', $p['comanda']['zona']); $this->assertCount(2, $p['zonas']);
        // Tablero: está preparándose; la cocina lo termina → listo para salir; sale con Pepe → en camino y WhatsApp; entregado en efectivo → cobrado y cerrado.
        $t = $this->get('/gastronomia/delivery')->assertOk()->viewData('page')['props']['tablero'];
        $this->assertSame([$c->id], array_column($t['preparando'], 'id'));
        $this->post("/gastronomia/comandas/{$c->id}/enviar"); $it = $c->items()->whereNotNull('product_id')->first(); $this->post("/gastronomia/items/{$it->id}/estado", ['estado' => 'listo']);
        $t = $this->get('/gastronomia/delivery')->viewData('page')['props']['tablero']; $this->assertSame([$c->id], array_column($t['listos'], 'id'));
        $this->post("/gastronomia/comandas/{$c->id}/salio", ['repartidor' => 'Pepe'])->assertSessionHas('success');
        $c->refresh(); $this->assertNotNull($c->salio_en); $this->assertSame('Pepe', $c->repartidor);
        Http::assertSent(fn($q) => str_contains($q->url(), 'graph.facebook.com') && str_contains($q->body(), 'ya sali') && str_contains($q->body(), 'Pepe') && str_contains($q->body(), '40 minutos'));
        $t = $this->get('/gastronomia/delivery')->viewData('page')['props']['tablero']; $this->assertSame('Pepe', $t['en_camino'][0]['repartidor']);
        $this->post("/gastronomia/comandas/{$c->id}/entregado", ['medio' => 'efectivo'])->assertSessionHas('success', fn($m) => str_contains($m, 'Entregado y cobrado'));
        $c->refresh(); $this->assertSame('cerrada', $c->estado); $this->assertNotNull($c->entregado_en); $this->assertNotNull($c->comprobante_id);
        $this->assertEqualsWithDelta(11200, (float) $c->comprobante->total, 0.02, 'La factura incluye el envío');
        $t = $this->get('/gastronomia/delivery')->viewData('page')['props']['tablero'];
        $this->assertSame(1, $t['kpis']['entregados_hoy']); $this->assertEqualsWithDelta(1200, $t['kpis']['envios_hoy'], 0.01); $this->assertSame(0, $t['kpis']['demora']);
        $this->assertContains('delivery', array_column($this->get('/gastronomia/delivery')->viewData('page')['props']['subnav']['items'], 'key'));
        $this->assertStringContainsString("'delivery_en_curso'", file_get_contents(app_path('Mcp/Modulos/Gestion.php')));
    }

    public function test_el_pedido_por_whatsapp_con_direccion_trae_el_envio_de_su_zona(): void
    {
        $this->empresa->forceFill(['gastronomia_config' => ['zonas' => [['nombre' => 'Centro', 'costo' => 800, 'palabras' => 'colon', 'demora' => 25]]], 'ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        $pizza = $this->articulo(['name' => 'Pizza muzzarella', 'price' => 10000, 'iva' => 0, 'tipo' => 'elaborado']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['items' => [['id' => $pizza->id, 'cantidad' => 1, 'opciones' => []]], 'direccion' => 'Colón 1234', 'entrega' => 'envio', 'nombre' => null, 'respuesta' => 'Anotado: 1 pizza muzzarella.'])]]]), 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);
        auth()->logout();
        $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => '3515550000', 'texto' => 'Hola, una pizza muzzarella a Colón 1234 por favor'])->assertOk()->assertJsonCount(1, 'pedidos');
        $c = Comanda::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('delivery', $c->tipo); $this->assertSame('Centro', $c->zona); $this->assertEqualsWithDelta(10800, (float) $c->total, 0.01);
        Http::assertSent(fn($q) => str_contains($q->url(), 'graph.facebook.com') && str_contains($q->body(), 'Centro') && str_contains($q->body(), '800'));
        $this->assertStringContainsString('lista de espera', file_get_contents(base_path('database/migrations/2027_01_15_000001_fase91_novedad_reservas_delivery.php')));
    }
}
