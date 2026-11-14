<?php

namespace Tests\Feature;

use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Mesa;
use Tests\ErpTestCase;

// Fase 95: pedido desde la mesa, seguro y en vivo: QR con token, código de la mesa para el segundo celular, "lo que pidieron",
// agotado hoy, cocina en marcha y semáforo del salón.
class MesaSeguraFase95Test extends ErpTestCase
{
    private function bar(): array
    {
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'tienda' => ['activa' => true, 'menu_activo' => true, 'slug' => 'bar-95']])->save();
        $cana = $this->articulo(['name' => 'Caña', 'price' => 2000, 'iva' => 0]); $cana->forceFill(['en_tienda' => true, 'va_cocina' => false])->save();
        $pulpo = $this->articulo(['name' => 'Pulpo', 'price' => 9000, 'iva' => 0]); $pulpo->forceFill(['en_tienda' => true, 'va_cocina' => true])->save();
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '5', 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        $this->assertNotEmpty($m->token, 'La mesa nace con token');
        return [$m, $cana, $pulpo];
    }

    public function test_qr_con_token_codigo_para_el_segundo_celular_y_lo_que_pidieron(): void
    {
        [$m, $cana, $pulpo] = $this->bar();
        $this->assertStringContainsString('?mesa=' . $m->token, $this->get('/gastronomia/reservas')->viewData('page')['props']['mesas'][0]['url']);
        auth()->logout();

        // Primer celular: abre la mesa por el token y pide. Recibe el código.
        $this->get("/m/bar-95?mesa={$m->token}")->assertOk()->assertDontSee('Esta mesa ya está abierta');
        $r = $this->post('/m/bar-95/pedir', ['mesa' => $m->token, 'items' => [['product_id' => $cana->id, 'cantidad' => 2]]])->assertOk();
        $c = Comanda::withoutGlobalScopes()->latest('id')->first();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $c->codigo); $r->assertSee($c->codigo);
        // El mismo celular ve "lo que pidieron" sin código, y el detalle en vivo.
        $this->get("/m/bar-95?mesa={$m->token}")->assertOk()->assertSee('Lo que pidieron')->assertSee($c->codigo);
        $this->getJson("/m/bar-95/pedido?mesa={$m->token}")->assertOk()->assertJsonPath('autorizada', true)->assertJsonPath('total', 4000)->assertJsonPath('items.0.cantidad', 2);

        // Segundo celular (otra sesión): la mesa está abierta, no puede pedir ni llamar sin el código.
        $this->flushSession();
        $this->get("/m/bar-95?mesa={$m->token}")->assertOk()->assertSee('Esta mesa ya está abierta')->assertDontSee('Lo que pidieron');
        $this->getJson("/m/bar-95/pedido?mesa={$m->token}")->assertOk()->assertJsonPath('autorizada', false)->assertJsonMissingPath('total');
        $this->post('/m/bar-95/pedir', ['mesa' => $m->token, 'items' => [['product_id' => $cana->id, 'cantidad' => 1]]])->assertSessionHasErrors('codigo');
        $this->post('/m/bar-95/llamar', ['mesa' => $m->token, 'motivo' => 'mozo'])->assertSessionHasErrors('codigo');
        $this->assertSame(2.0, (float) $c->items()->sum('cantidad'));
        // Código equivocado no; el correcto lo deja adentro y lo que pide va a la misma cuenta.
        $this->post('/m/bar-95/codigo', ['mesa' => $m->token, 'codigo' => $c->codigo === '0000' ? '1111' : '0000'])->assertSessionHasErrors('codigo');
        $this->post('/m/bar-95/codigo', ['mesa' => $m->token, 'codigo' => $c->codigo])->assertSessionHasNoErrors();
        $this->post('/m/bar-95/pedir', ['mesa' => $m->token, 'items' => [['product_id' => $pulpo->id, 'cantidad' => 1]]])->assertOk();
        $this->assertSame(1, Comanda::withoutGlobalScopes()->where('mesa_id', $m->id)->count(), 'Misma comanda, no otra');
        $this->assertEquals(13000, (float) $c->fresh()->total);
        // El código también vale directo en el pedido (sin pasar por /codigo) y un id numérico viejo sigue abriendo la carta.
        $this->flushSession();
        $this->post('/m/bar-95/pedir', ['mesa' => $m->token, 'codigo' => $c->codigo, 'items' => [['product_id' => $cana->id, 'cantidad' => 1]]])->assertOk();
        $this->get("/m/bar-95?mesa={$m->id}")->assertOk();
        // Un token inventado no abre ninguna mesa.
        $this->post('/m/bar-95/pedir', ['mesa' => 'no-existe', 'items' => [['product_id' => $cana->id, 'cantidad' => 1]]])->assertNotFound();

        // El mozo y el salón ven el código y el semáforo.
        $this->actingAs($this->dueno);
        $this->assertSame($c->codigo, $this->get('/gastronomia/mozo')->viewData('page')['props']['mias'][0]['codigo']);
        $mesa = collect($this->get('/gastronomia')->viewData('page')['props']['mesas'])->firstWhere('id', $m->id);
        $this->assertSame($c->codigo, $mesa['comanda']['codigo']);
        $this->assertSame('cocina', $mesa['comanda']['semaforo']);
        // Al cerrar la mesa y abrir otra comanda, el código cambia.
        $c->update(['estado' => 'cerrada', 'cerrada_en' => now()]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id, 'cubiertos' => 2]);
        $c2 = Comanda::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotSame($c->id, $c2->id); $this->assertMatchesRegularExpression('/^\d{4}$/', $c2->codigo);
    }

    public function test_agotado_hoy_cocina_en_marcha_y_semaforo(): void
    {
        [$m, $cana, $pulpo] = $this->bar();
        // "Se acabó el pulpo": sale de la carta QR y el pedido que lo trae se rechaza.
        $this->post("/gastronomia/carta/{$pulpo->id}/agotado", ['agotado' => true])->assertSessionHas('success');
        $this->assertTrue($pulpo->fresh()->agotadoHoy());
        $this->assertTrue(collect($this->get('/gastronomia/carta')->viewData('page')['props']['productos'])->firstWhere('id', $pulpo->id)['agotado']);
        $this->assertFalse(collect($this->get("/gastronomia/comandas/" . $this->abrirMesa($m))->viewData('page')['props']['productos'])->contains('id', $pulpo->id), 'El salón tampoco lo ofrece');
        auth()->logout();
        $this->get("/m/bar-95?mesa={$m->token}")->assertOk()->assertSee('data-agotado="' . $pulpo->id . '"', false);
        $this->post('/m/bar-95/pedir', ['mesa' => $m->token, 'items' => [['product_id' => $pulpo->id, 'cantidad' => 1]]])->assertSessionHasErrors('agotado');
        // Vuelve a estar disponible: se puede pedir.
        $this->actingAs($this->dueno);
        $this->post("/gastronomia/carta/{$pulpo->id}/agotado", ['agotado' => false]);
        $this->assertFalse($pulpo->fresh()->agotadoHoy());
        auth()->logout();
        $this->post('/m/bar-95/pedir', ['mesa' => $m->token, 'items' => [['product_id' => $pulpo->id, 'cantidad' => 1]]])->assertOk();
        $c = Comanda::withoutGlobalScopes()->where('mesa_id', $m->id)->abiertas()->first(); $it = $c->items()->where('product_id', $pulpo->id)->first();
        $this->assertSame('cocina', $it->estado);

        // Cocina: Nuevo → En marcha → Listo; el cliente lo ve en "lo que pidieron" y el semáforo cambia a "listo".
        $this->actingAs($this->dueno);
        $this->assertFalse(collect($this->get('/gastronomia/cocina?json=1')->json())->flatMap(fn($g) => $g['items'])->firstWhere('id', $it->id)['empezado']);
        $this->post("/gastronomia/items/{$it->id}/empezar");
        $this->assertNotNull($it->fresh()->empezado_en); $this->assertSame('cocina', $it->fresh()->estado);
        $this->assertTrue(collect($this->get('/gastronomia/cocina?json=1')->json())->flatMap(fn($g) => $g['items'])->firstWhere('id', $it->id)['empezado']);
        $this->assertSame('cocina', collect($this->get('/gastronomia')->viewData('page')['props']['mesas'])->firstWhere('id', $m->id)['comanda']['semaforo']);
        auth()->logout();
        $this->get("/m/bar-95?mesa={$m->token}"); // misma sesión que pidió: autorizada
        $this->assertSame('En marcha', collect($this->getJson("/m/bar-95/pedido?mesa={$m->token}")->json('items'))->firstWhere('estado', 'cocina')['etiqueta']);
        $this->actingAs($this->dueno);
        $this->post("/gastronomia/items/{$it->id}/estado", ['estado' => 'listo']);
        $this->assertSame('listo', collect($this->get('/gastronomia')->viewData('page')['props']['mesas'])->firstWhere('id', $m->id)['comanda']['semaforo']);
        // Llamar al mozo pisa todo: semáforo "llama" con los minutos.
        auth()->logout();
        $this->post('/m/bar-95/llamar', ['mesa' => $m->token, 'motivo' => 'mozo'])->assertSessionHas('llamado', 'mozo');
        $this->actingAs($this->dueno);
        $this->assertSame('llama', collect($this->get('/gastronomia')->viewData('page')['props']['mesas'])->firstWhere('id', $m->id)['comanda']['semaforo']);
        // Bigcito: "se acabó el pulpo".
        $cat = app(\App\Mcp\Catalogo::class);
        $prop = $cat->llamar($this->dueno, 'marcar_agotado', ['plato' => 'Pulpo'], 'asistente');
        $this->assertStringContainsString('agotado', $prop['titulo']);
        $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente');
        $this->assertTrue($pulpo->fresh()->agotadoHoy());
        $prop = $cat->llamar($this->dueno->fresh(), 'marcar_agotado', ['plato' => 'Pulpo', 'disponible' => true], 'asistente');
        $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente');
        $this->assertFalse($pulpo->fresh()->agotadoHoy());
        // "¿qué mesa me llama?" y "¿cuál es el código de la mesa 5?"
        $r = $cat->llamar($this->dueno->fresh(), 'salon_ahora', [], 'asistente');
        $this->assertSame(1, $r['urgentes']); $this->assertSame('llama', $r['mesas'][0]['semaforo']); $this->assertStringContainsString('llama al mozo', $r['resumen']);
        $r = $cat->llamar($this->dueno->fresh(), 'salon_ahora', ['mesa' => 'mesa 5'], 'asistente');
        $this->assertSame($c->codigo, $r['mesas'][0]['codigo']); $this->assertNotEmpty($r['mesas'][0]['platos']);
        $this->assertFalse($cat->llamar($this->dueno->fresh(), 'salon_ahora', ['mesa' => '99'], 'asistente')['abierta']);
    }

    private function abrirMesa(Mesa $m): int
    {
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id, 'cubiertos' => 2]);
        $id = Comanda::withoutGlobalScopes()->where('mesa_id', $m->id)->abiertas()->value('id');
        Comanda::withoutGlobalScopes()->find($id)->update(['estado' => 'cerrada', 'cerrada_en' => now()]); // sólo para mirar la carta del salón
        return $id;
    }
}
