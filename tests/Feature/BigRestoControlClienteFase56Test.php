<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Models\RestoOpinion;
use App\Services\Gastronomia\CartaQrService;
use App\Services\Gastronomia\ControlSalonService;
use App\Services\Gastronomia\IngenieriaMenuService;
use App\Services\Gastronomia\OpinionesService;
use App\Services\Ventas\AvisosDuenoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 56: control del salón por mozo, encuesta al pagar con patrón y resumen IA, ingeniería de menú, parte del dueño,
// y en la carta QR el agregado sugerido y la traducción.
class BigRestoControlClienteFase56Test extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'tienda' => ['activa' => true, 'menu_activo' => true, 'slug' => 'resto-test']])->save();
    }

    private function cerrada(array $platos, Carbon $cuando, ?int $mozo = null, array $extra = []): Comanda
    {
        $c = Comanda::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'user_id' => $mozo ?? $this->dueno->id, 'numero' => (Comanda::withoutGlobalScopes()->max('numero') ?? 0) + 1, 'tipo' => 'mesa', 'estado' => 'cerrada', 'total' => 0, 'propina' => $extra['propina'] ?? 0, 'descuento' => $extra['descuento'] ?? 0, 'cubiertos' => 2, 'abierta_en' => $cuando->copy()->subMinutes(50), 'cerrada_en' => $cuando]);
        foreach ($platos as [$p, $n]) $c->items()->create(['product_id' => $p->id, 'descripcion' => $p->name, 'cantidad' => $n, 'precio_unit' => (float) $p->price, 'alicuota_iva' => 0, 'va_cocina' => true, 'ronda' => 1, 'estado' => 'entregado', 'enviado_en' => $cuando->copy()->subMinutes(40), 'listo_en' => $cuando->copy()->subMinutes(25)]);
        $c->recalcular(); return $c;
    }

    public function test_control_del_salon_marca_lo_que_se_aparta_del_equipo(): void
    {
        $p = $this->articulo(['name' => 'Milanesa', 'price' => 8000, 'iva' => 0]);
        $juan = $this->usuarioConRol('vendedor', 'juan@t.com'); $ana = $this->usuarioConRol('cajero', 'ana@t.com');
        Carbon::setTestNow(now()->next('monday')->setTime(9, 0)); $sem = now()->subWeek()->startOfWeek()->addDays(2)->setTime(21, 0);
        for ($i = 0; $i < 6; $i++) { $this->cerrada([[$p, 2]], $sem->copy()->addHours($i), $ana->id, ['propina' => 1600]); $c = $this->cerrada([[$p, 2]], $sem->copy()->addHours($i), $juan->id, ['descuento' => 4000]); if ($i < 4) $c->items()->create(['product_id' => $p->id, 'descripcion' => 'Milanesa', 'cantidad' => 1, 'precio_unit' => 8000, 'alicuota_iva' => 0, 'va_cocina' => true, 'ronda' => 2, 'estado' => 'anulado', 'enviado_en' => $sem]); }
        $d = app(ControlSalonService::class)->datos($this->empresa, now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek());
        $j = collect($d['mozos'])->firstWhere('mozo', 'Usuario vendedor'); $a = collect($d['mozos'])->firstWhere('mozo', 'Usuario cajero');
        $this->assertSame(4, $j['items_anulados']); $this->assertSame(25.0, $j['descuento_pct']);
        $this->assertNotEmpty($j['raros']); $this->assertStringContainsString('anulados', $j['raros'][0]); $this->assertStringContainsString('descuentos', $j['raros'][1]);
        $this->assertSame([], $a['raros']);
        $this->assertSame(1, app(ControlSalonService::class)->alertarSemanal($this->empresa));
        $this->assertTrue(Alerta::where('tipo', 'salon_control')->where('titulo', 'like', '%vendedor%')->exists());
        $this->assertNotEmpty(app(Catalogo::class)->llamar($this->dueno, 'control_salon', ['desde' => now()->subWeek()->startOfWeek()->toDateString()], 'asistente')['mozos']);
        Carbon::setTestNow();
    }

    public function test_encuesta_al_pagar_patron_de_quejas_resumen_ia_y_parte_del_dueno(): void
    {
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '1'], 'ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]]), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['puntos' => ['3 clientes dijeron que la milanesa llegó fría, siempre los viernes a la noche'], 'lo_mas_repetido' => 'Comida fría los viernes', 'elogios' => 'La atención'])]]])]);
        $p = $this->articulo(['name' => 'Tostado', 'price' => 4000]); $p->forceFill(['va_cocina' => true])->save();
        // Pedido de mostrador: al quedar listo, el WhatsApp lleva el link de la encuesta
        $this->post('/gastronomia/comandas', ['tipo' => 'mostrador', 'cliente' => 'Lu', 'telefono' => '351555']); $c = Comanda::latest('id')->first();
        $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $p->id]); $this->post("/gastronomia/comandas/{$c->id}/enviar");
        $this->post('/gastronomia/items/' . $c->items()->first()->id . '/estado', ['estado' => 'listo']);
        Http::assertSent(fn($r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r->body(), 'opinion?c=' . $c->id));
        auth()->logout();
        $this->get("/m/resto-test/opinion?c={$c->id}&f=mala")->assertNotFound();
        $f = OpinionesService::firma($c);
        $this->get("/m/resto-test/opinion?c={$c->id}&f={$f}")->assertOk()->assertSee('¿Cómo estuvo?');
        $this->post("/m/resto-test/opinion?c={$c->id}&f={$f}&canal=wa", ['puntaje' => 2, 'comentario' => 'Llegó frío'])->assertOk()->assertSee('Gracias');
        $op = RestoOpinion::withoutGlobalScopes()->first(); $this->assertSame(2, $op->puntaje); $this->assertSame('whatsapp', $op->canal); $this->assertSame($c->id, $op->comanda_id);
        $this->assertTrue(Alerta::withoutGlobalScopes()->where('tipo', 'opinion_mala')->exists());
        $this->post("/m/resto-test/opinion?c={$c->id}&f={$f}", ['puntaje' => 5])->assertOk(); $this->assertSame(1, RestoOpinion::withoutGlobalScopes()->count(), 'Una sola opinión por comanda');

        // Tres malas un viernes a la noche → el patrón; el resumen IA se guarda y no se vuelve a pedir
        $this->actingAs($this->dueno);
        $vie = now()->subDays(10)->next('friday')->subWeek()->setTime(22, 0);
        foreach ([1, 2, 3] as $i) { $cc = $this->cerrada([[$p, 1]], $vie->copy()->addMinutes($i)); RestoOpinion::create(['business_id' => $this->empresa->id, 'comanda_id' => $cc->id, 'puntaje' => 1, 'comentario' => "Frío {$i}", 'canal' => 'qr'])->forceFill(['created_at' => $vie, 'updated_at' => $vie])->save(); }
        $r = app(OpinionesService::class)->resumen($this->empresa);
        $this->assertSame(4, $r['cantidad']); $this->assertSame('viernes a la noche', $r['patron']['cuando']); $this->assertSame(3, $r['patron']['cantidad']);
        $this->assertStringContainsString('viernes', $r['ia']['puntos'][0]);
        Http::assertSentCount(2); app(OpinionesService::class)->resumen($this->empresa); Http::assertSentCount(2);
        $props = $this->get('/estadisticas/gastronomia')->assertOk()->viewData('page')['props'];
        $this->assertSame(4, $props['opiniones']['cantidad']);

        // El parte del dueño trae el bloque de Big Restó
        $this->cerrada([[$p, 3]], today()->setTime(13, 0), null, ['propina' => 500]);
        $texto = app(AvisosDuenoService::class)->resumen($this->empresa, today());
        $this->assertStringContainsString('Big Restó', $texto); $this->assertStringContainsString('Tostado', $texto);
    }

    public function test_ingenieria_de_menu_clasifica_y_propone_acciones(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        $estrella = $this->articulo(['name' => 'Bife', 'price' => 10000, 'iva' => 0, 'cost' => 3000]);
        $caballito = $this->articulo(['name' => 'Pizza', 'price' => 6000, 'iva' => 0, 'cost' => 5000]);
        $perro = $this->articulo(['name' => 'Sopa', 'price' => 3000, 'iva' => 0, 'cost' => 2800]);
        $h = now()->subDays(3)->setTime(21, 0);
        $this->cerrada([[$estrella, 20], [$caballito, 20], [$perro, 1]], $h);
        $m = app(IngenieriaMenuService::class)->matriz($this->empresa);
        $clase = collect($m['platos'])->pluck('clase', 'plato');
        $this->assertSame('estrella', $clase['Bife']); $this->assertSame('caballito', $clase['Pizza']); $this->assertSame('perro', $clase['Sopa']);
        $this->assertStringContainsString('subí el precio', collect($m['platos'])->firstWhere('plato', 'Pizza')['accion']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['acciones' => ['Subí la Pizza a $7.500: +$1.500 por unidad.', 'Sacá la Sopa.', 'Destacá el Bife.']])]]])]);
        $this->assertCount(3, $this->postJson('/estadisticas/gastronomia/acciones')->assertOk()->json('acciones'));
        $this->assertSame('estrella', collect(app(Catalogo::class)->llamar($this->dueno, 'ingenieria_de_menu', [], 'asistente')['platos'])->firstWhere('plato', 'Bife')['clase']);
    }

    public function test_carta_qr_sugiere_el_acompanante_y_se_traduce(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        $pizza = $this->articulo(['name' => 'Pizza muzzarella', 'price' => 9000, 'iva' => 0]); $coca = $this->articulo(['name' => 'Coca Cola', 'price' => 2500, 'iva' => 0]);
        foreach ([$pizza, $coca] as $x) $x->forceFill(['en_tienda' => true])->save();
        foreach ([1, 2, 3] as $i) $this->cerrada([[$pizza, 1], [$coca, 2]], now()->subDays($i)->setTime(21, 0));
        $s = app(CartaQrService::class)->acompanantes($this->empresa);
        $this->assertSame($coca->id, $s[$pizza->id]['id']); $this->assertSame(3, $s[$pizza->id]['veces']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['items' => [['id' => $pizza->id, 'nombre' => 'Mozzarella pizza', 'descripcion' => 'Classic'], ['id' => $coca->id, 'nombre' => 'Coke']]])]]])]);
        $this->post('/gastronomia/carta/traducir', ['idioma' => 'en'])->assertSessionHas('success');
        $this->assertSame('Mozzarella pizza', $pizza->fresh()->traducciones['en']['nombre']);
        $this->post('/gastronomia/carta/traducir', ['idioma' => 'en'])->assertSessionHas('success'); Http::assertSentCount(1);
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '9', 'sector' => 'Salón', 'capacidad' => 2, 'activa' => true]);
        auth()->logout();
        $this->get("/m/resto-test?mesa={$m->id}")->assertOk()->assertSee('Pizza muzzarella')->assertSee('"nombre":"Coca Cola"', false)->assertSee('lang=en', false);
        $this->get("/m/resto-test?mesa={$m->id}&lang=en")->assertOk()->assertSee('Mozzarella pizza')->assertSee('Call the waiter')->assertDontSee('Pizza muzzarella');
    }
}
