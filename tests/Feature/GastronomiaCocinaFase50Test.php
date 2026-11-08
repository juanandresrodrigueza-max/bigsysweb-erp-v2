<?php

namespace Tests\Feature;

use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Mesa;
use Tests\ErpTestCase;

// Fase 50: estaciones de cocina con su pantalla, su alerta de demora y su comanda impresa.
class GastronomiaCocinaFase50Test extends ErpTestCase
{
    public function test_cada_estacion_ve_lo_suyo_se_marca_lo_demorado_y_se_imprime(): void
    {
        $this->empresa->forceFill(['vertical' => 'gastronomia'])->save();
        $this->post('/gastronomia/estaciones', ['estaciones' => [['nombre' => 'Cocina', 'alerta' => 20], ['nombre' => 'Parrilla', 'alerta' => 25], ['nombre' => 'Barra', 'alerta' => 5]]])->assertSessionHas('success');
        $bife = $this->articulo(['name' => 'Bife', 'price' => 10000]); $trago = $this->articulo(['name' => 'Fernet', 'price' => 4000]); $ensalada = $this->articulo(['name' => 'Ensalada', 'price' => 5000]);
        $this->post("/gastronomia/carta/{$bife->id}", ['va_cocina' => true, 'estacion' => 'Parrilla'])->assertSessionHas('success');
        $this->post("/gastronomia/carta/{$trago->id}", ['va_cocina' => true, 'estacion' => 'Barra']);
        $this->post("/gastronomia/carta/{$ensalada->id}", ['va_cocina' => true]);
        $this->get('/gastronomia/carta')->assertInertia(fn($p) => $p->has('estaciones', 3)->etc());

        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => '1', 'sector' => 'Salón', 'capacidad' => 2, 'activa' => true]);
        $this->post('/gastronomia/comandas', ['mesa_id' => $m->id]); $c = Comanda::latest('id')->first();
        foreach ([$bife, $trago, $ensalada] as $p) $this->post("/gastronomia/comandas/{$c->id}/items", ['product_id' => $p->id]);
        $this->post("/gastronomia/comandas/{$c->id}/enviar")->assertSessionHas('success');
        $this->assertSame('Cocina', ComandaItem::where('product_id', $ensalada->id)->value('estacion'), 'Sin estación va a la primera');

        $parrilla = $this->get('/gastronomia/cocina?estacion=Parrilla')->viewData('page')['props'];
        $this->assertSame(['Bife'], collect($parrilla['grupos'])->flatMap(fn($g) => collect($g['items'])->pluck('descripcion'))->all());
        $this->assertCount(3, collect($this->get('/gastronomia/cocina')->viewData('page')['props']['grupos'])->flatMap(fn($g) => $g['items']));

        // La barra avisa a los 5 minutos
        ComandaItem::where('product_id', $trago->id)->update(['enviado_en' => now()->subMinutes(7)]);
        $it = collect($this->get('/gastronomia/cocina?estacion=Barra')->viewData('page')['props']['grupos'][0]['items'])->first();
        $this->assertSame(5, $it['alerta']); $this->assertGreaterThanOrEqual(7, $it['minutos']);

        $this->get("/gastronomia/comandas/{$c->id}/imprimir?estacion=Parrilla")->assertOk()->assertSee('Bife')->assertDontSee('Fernet')->assertSee('<h1>Parrilla</h1>', false);
    }
}
