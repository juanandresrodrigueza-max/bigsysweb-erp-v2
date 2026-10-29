<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\MejoraInteres;
use App\Models\Novedad;
use App\Models\PushSuscripcion;
use App\Models\SistemaConfig;
use App\Services\Avisos\CelularService;
use Tests\ErpTestCase;

// Fase 40.5: avisos al celular, novedades con posponer, parte de la mañana y mejoras; todo también por Bigcito.
class AvisosCelularTest extends ErpTestCase
{
    private function mcp(string $h, array $a = []): array { return app(Catalogo::class)->llamar($this->dueno, $h, $a, 'asistente'); }

    public function test_suscribir_un_dispositivo_y_la_clave_privada_va_cifrada(): void
    {
        $this->actingAs($this->dueno)->get('/avisos-celular')->assertInertia(fn($p) => $p->component('Avisos/Celular', false)->where('clave', fn($c) => strlen($c) > 80));
        $raw = \Illuminate\Support\Facades\DB::table('sistema_config')->where('clave', 'vapid')->value('valor');
        $this->assertStringNotContainsString('privateKey', (string) $raw);
        $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'], 'dispositivo' => 'Chrome en Android'];
        $this->actingAs($this->dueno)->postJson('/avisos-celular/suscribir', $sub)->assertOk();
        $this->actingAs($this->dueno)->postJson('/avisos-celular/suscribir', $sub)->assertOk(); // el mismo dispositivo no se duplica
        $this->assertSame(1, PushSuscripcion::where('user_id', $this->dueno->id)->count());
        $this->actingAs($this->dueno)->postJson('/avisos-celular/desuscribir', ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertSame(0, PushSuscripcion::count());
    }

    public function test_novedades_se_posponen_y_se_leen(): void
    {
        $a = Novedad::create(['titulo' => 'Conciliación de tarjetas', 'texto' => 'Nueva', 'publicada_en' => now()]);
        $b = Novedad::create(['titulo' => 'Sólo para hoteles', 'texto' => 'x', 'modulo' => 'hoteleria', 'publicada_en' => now()]);
        $s = app(CelularService::class);
        $this->assertSame(['Conciliación de tarjetas'], array_column($s->novedades($this->dueno), 'titulo'));
        $this->actingAs($this->dueno)->postJson("/novedades/{$a->id}", ['posponer' => 7])->assertOk()->assertJson(['sin_leer' => 0]);
        $this->assertSame([], $s->novedades($this->dueno));
        $this->travel(8)->days();
        $this->assertCount(1, $s->novedades($this->dueno));
        $p = $this->mcp('posponer_novedad', ['id' => $a->id, 'dias' => 0]); $this->mcp('confirmar_accion', ['propuesta_id' => $p['propuesta_id']]);
        $this->assertTrue($s->novedades($this->dueno)[0]['leida']);
    }

    public function test_parte_de_la_manana_a_la_hora_y_una_sola_vez(): void
    {
        $cli = $this->cliente(['name' => 'Cliente Hoy']);
        $f = $this->factura($cli, [['descripcion' => 'x', 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]); $f->forceFill(['fecha_vto' => today()])->save();
        $p = $this->mcp('configurar_parte_manana', ['hora' => '07:30']); $this->mcp('confirmar_accion', ['propuesta_id' => $p['propuesta_id']]);
        $b = $this->empresa->fresh(); $s = app(CelularService::class);
        $this->assertTrue($s->configManana($b)['activo']);
        $this->assertStringContainsString('Hoy vencen cobros por $ 1.210', $this->mcp('parte_de_la_manana')['parte']);
        $this->travelTo(today()->setTime(7, 0));
        $this->assertFalse($s->enviarParteSiCorresponde($b->fresh()));
        $this->travelTo(today()->setTime(7, 45));
        $this->assertTrue($s->enviarParteSiCorresponde($b->fresh()));
        $this->assertFalse($s->enviarParteSiCorresponde($b->fresh()));
        $this->artisan('avisos:dueno')->assertSuccessful();
    }

    public function test_mejoras_y_me_interesa(): void
    {
        $m = app(CelularService::class)->mejoras($this->empresa);
        $this->assertNotEmpty($m); $mod = $m[0]['modulo'];
        $this->assertNotContains('comprobantes', array_column($m, 'modulo'));
        $this->actingAs($this->dueno)->post('/mejoras', ['modulo' => $mod, 'comentario' => 'Quiero precio'])->assertRedirect();
        $this->assertSame('Quiero precio', MejoraInteres::where('business_id', $this->empresa->id)->value('comentario'));
        $this->assertTrue(collect($this->mcp('mejoras_disponibles')['mejoras'])->firstWhere('modulo', $mod)['interesado']);
        $this->assertArrayHasKey('error', $this->mcp('me_interesa_modulo', ['modulo' => 'comprobantes']));
        $vend = $this->usuarioConRol('vendedor', 'v2@x.com');
        $this->actingAs($vend)->post('/mejoras', ['modulo' => $mod])->assertForbidden();
    }

    public function test_superadmin_publica_novedad(): void
    {
        $this->dueno->forceFill(['is_superadmin' => true])->save();
        $this->actingAs($this->dueno->fresh())->post('/admin/novedades', ['titulo' => 'Bigcito mira fotos', 'texto' => 'Probalo'])->assertRedirect();
        $this->assertSame(1, app(CelularService::class)->sinLeer($this->dueno));
    }
}
