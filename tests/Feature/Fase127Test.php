<?php

namespace Tests\Feature;

use App\Models\Mesa;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 127: la carta vitrina de Big Restó (fotos subidas, ingredientes, alérgenos, historia del local).
class Fase127Test extends ErpTestCase
{
    private Product $pizza;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa->forceFill(['vertical' => 'gastronomia', 'tienda' => ['activa' => true, 'menu_activo' => true, 'slug' => 'pizzeria-test']])->save();
        $this->pizza = $this->articulo(['name' => 'Marinara', 'price' => 9500]);
        $this->pizza->forceFill(['en_tienda' => true, 'va_cocina' => true, 'description' => 'La más antigua de Nápoles.', 'modificadores' => [['nombre' => 'Tamaño', 'obligatorio' => true, 'multiple' => false, 'opciones' => [['nombre' => 'Normal 30 cm', 'precio' => 0], ['nombre' => 'Grande 38 cm', 'precio' => 3000]]]]])->save();
    }

    private function vitrina(array $extra = []): void
    {
        $this->post('/gastronomia/carta/vitrina', array_merge(['menu_estilo' => 'vitrina', 'menu_historia_titulo' => 'Leña de espinillo. Noventa segundos.', 'menu_historia' => 'La masa reposa dos días antes de entrar al horno.', 'menu_horario' => 'Martes a domingo · 20 a 24'], $extra))->assertSessionHas('success');
    }

    public function test_la_foto_del_plato_se_sube_se_achica_y_se_ve_desde_afuera(): void
    {
        $this->post("/gastronomia/carta/{$this->pizza->id}/foto", ['foto' => UploadedFile::fake()->image('pizza.jpg', 2400, 1800)])->assertSessionHas('success');
        $p = $this->pizza->fresh();
        $this->assertNotNull($p->foto_carta); Storage::disk('local')->assertExists($p->foto_carta);
        $this->assertStringContainsString("/fotos/plato/{$p->id}?v=", $p->imagen);
        [$w, $h] = getimagesize(Storage::disk('local')->path($p->foto_carta));
        $this->assertSame(1200, $w); $this->assertSame(900, $h);
        // Pública, sin entrar al sistema.
        auth()->logout();
        $r = $this->get("/fotos/plato/{$p->id}")->assertOk();
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age', $r->headers->get('Cache-Control'));
        // Una que no es foto, no.
        $this->actingAs($this->dueno);
        $this->post("/gastronomia/carta/{$p->id}/foto", ['foto' => UploadedFile::fake()->create('carta.pdf', 50, 'application/pdf')])->assertSessionHasErrors('foto');
        // Se quita y deja de verse.
        $vieja = $p->foto_carta;
        $this->delete("/gastronomia/carta/{$p->id}/foto")->assertSessionHas('success');
        $this->assertNull($p->fresh()->foto_carta); $this->assertNull($p->fresh()->imagen); Storage::disk('local')->assertMissing($vieja);
        $this->get("/fotos/plato/{$p->id}")->assertNotFound();
    }

    public function test_un_png_recortado_conserva_la_transparencia(): void
    {
        $img = imagecreatetruecolor(400, 400); imagesavealpha($img, true); imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledellipse($img, 200, 200, 300, 300, imagecolorallocate($img, 200, 60, 40));
        $tmp = tempnam(sys_get_temp_dir(), 'pz') . '.png'; imagepng($img, $tmp);
        $this->post("/gastronomia/carta/{$this->pizza->id}/foto", ['foto' => new UploadedFile($tmp, 'pizza.png', 'image/png', null, true)])->assertSessionHas('success');
        $this->assertStringEndsWith('.png', $this->pizza->fresh()->foto_carta);
        @unlink($tmp);
    }

    public function test_ingredientes_alergenos_y_que_gire_se_guardan_sin_tocar_las_opciones(): void
    {
        $mods = $this->pizza->modificadores;
        $this->post("/gastronomia/carta/{$this->pizza->id}", ['modificadores' => $mods, 'ingredientes' => 'Tomate San Marzano, ajo, orégano, aceite de oliva', 'alergenos' => ['gluten', 'gluten'], 'foto_gira' => true])->assertSessionHas('success');
        $p = $this->pizza->fresh();
        $this->assertSame(['gluten'], $p->alergenos); $this->assertTrue($p->foto_gira); $this->assertStringContainsString('San Marzano', $p->ingredientes);
        $this->assertSame('Tamaño', $p->modificadores[0]['nombre']);
        $this->post("/gastronomia/carta/{$this->pizza->id}", ['modificadores' => $mods, 'alergenos' => ['veneno']])->assertSessionHasErrors('alergenos.0');
        // La pantalla de Carta lo trae, con la lista de alérgenos del Código Alimentario y la configuración de la vitrina.
        $props = $this->get('/gastronomia/carta')->viewData('page')['props'];
        $fila = collect($props['productos'])->firstWhere('id', $p->id);
        $this->assertSame(['gluten'], $fila['alergenos']); $this->assertTrue($fila['foto_gira']);
        $this->assertArrayHasKey('frutos_secos', $props['alergenosLista']); $this->assertSame('lista', $props['vitrina']['estilo']);
        $this->assertStringEndsWith('/m/pizzeria-test', $props['vitrina']['url']);
    }

    public function test_la_carta_del_qr_en_vitrina_muestra_el_local_y_cada_plato(): void
    {
        $this->pizza->forceFill(['ingredientes' => 'Tomate San Marzano, ajo, orégano', 'alergenos' => ['gluten', 'leche'], 'foto_gira' => true, 'imagen' => 'https://example.com/marinara.jpg'])->save();
        $otra = $this->articulo(['name' => 'Funghi e tartufo', 'price' => 15900]); $otra->forceFill(['en_tienda' => true])->save();
        // Con la carta en lista (lo de siempre) no aparece la vitrina.
        $this->get('/m/pizzeria-test')->assertOk()->assertDontSee('data-vitrina', false);
        $this->vitrina();
        $this->post('/gastronomia/carta/portada', ['foto' => UploadedFile::fake()->image('horno.jpg', 1600, 900)])->assertSessionHas('success');
        auth()->logout();
        $html = $this->get('/m/pizzeria-test')->assertOk()
            ->assertSee('data-vitrina', false)->assertSee('Leña de espinillo. Noventa segundos.')->assertSee('La masa reposa dos días')->assertSee('Martes a domingo · 20 a 24')
            ->assertSee('Marinara')->assertSee('Funghi e tartufo')->assertSee('San Marzano')->assertSee('Contiene')->assertSee('Gluten (TACC)')->assertSee('Leche')
            ->assertSee('Grande 38 cm (+$ 3.000)', false)->assertSee('vt-foto gira', false)->assertSee('Para pedir, escaneá el QR de tu mesa')
            ->assertDontSee('id="q' . $this->pizza->id . '"', false)->getContent();
        $this->assertMatchesRegularExpression('#/fotos/portada/' . $this->empresa->id . '\?v=#', $html);
        $this->get('/fotos/portada/' . $this->empresa->id)->assertOk();
        // En otro idioma, los textos de la vitrina y los alérgenos también.
        $this->get('/m/pizzeria-test?lang=en')->assertOk()->assertSee('Contains')->assertSee('Milk');
    }

    public function test_desde_la_mesa_se_pide_igual_que_en_la_lista(): void
    {
        $this->vitrina();
        $m = Mesa::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => 'Mesa 4', 'sector' => 'Salón', 'capacidad' => 4, 'activa' => true]);
        auth()->logout();
        $this->get('/m/pizzeria-test?mesa=' . $m->tokenQr())->assertOk()->assertSee('data-vitrina', false)
            ->assertSee('id="q' . $this->pizza->id . '"', false)->assertSee('Enviar a cocina')->assertSee('Mesa 4');
        $this->post('/m/pizzeria-test/pedir', ['mesa' => $m->tokenQr(), 'items' => [['product_id' => $this->pizza->id, 'cantidad' => 1, 'modificadores' => ['Tamaño' => ['Grande 38 cm']]]]])->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\ComandaItem::withoutGlobalScopes()->where('product_id', $this->pizza->id)->count());
    }

    public function test_el_estilo_tiene_que_ser_uno_de_los_dos(): void
    {
        $this->post('/gastronomia/carta/vitrina', ['menu_estilo' => 'otro'])->assertSessionHasErrors('menu_estilo');
        $this->vitrina(['menu_estilo' => 'lista']);
        $this->assertSame('lista', $this->empresa->fresh()->tienda['menu_estilo']);
        $this->assertSame('pizzeria-test', $this->empresa->fresh()->tienda['slug'], 'No pisa el resto de la configuración de la tienda');
    }
}
