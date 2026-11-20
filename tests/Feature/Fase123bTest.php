<?php

namespace Tests\Feature;

use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 123.1: «Probar la casilla» prueba lo que está escrito en la pantalla, aunque todavía no se haya guardado.
class Fase123bTest extends ErpTestCase
{
    private function superadmin(): void
    {
        $su = User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); Auth::login($su);
    }

    public function test_prueba_lo_escrito_sin_guardar_y_avisa_que_no_se_guardo(): void
    {
        $this->superadmin();
        SistemaConfig::set('casilla_host', 'imap.guardado.invalid'); SistemaConfig::set('casilla_usuario', 'vieja@bigsysweb.com'); SistemaConfig::set('casilla_clave', 'vieja');
        // Con lo guardado: falla contra el servidor guardado, sin la aclaración.
        $this->post('/admin/sistema/probar-casilla')->assertSessionHas('prueba', fn($p) => $p['que'] === 'casilla' && ! $p['ok'] && str_contains($p['texto'], 'imap.guardado.invalid') && ! str_contains($p['texto'], 'escrito'));
        // Con lo escrito en la pantalla: usa ese servidor y lo dice.
        $this->post('/admin/sistema/probar-casilla', ['casilla_host' => 'imap.escrito.invalid', 'casilla_puerto' => 993, 'casilla_usuario' => 'facturacion@bigsysweb.com', 'casilla_clave' => 'nueva'])
            ->assertSessionHas('prueba', fn($p) => ! $p['ok'] && str_contains($p['texto'], 'imap.escrito.invalid') && str_contains($p['texto'], 'Se probó con lo que está escrito'));
        // No se guardó nada.
        $this->assertSame('vieja', SistemaConfig::get('casilla_clave'));
        $this->assertSame('imap.guardado.invalid', SistemaConfig::get('casilla_host'));
    }

    public function test_sin_nada_cargado_pide_los_datos(): void
    {
        $this->superadmin();
        $this->post('/admin/sistema/probar-casilla')->assertSessionHas('prueba', fn($p) => ! $p['ok'] && str_contains($p['texto'], 'Falta cargar'));
    }
}
