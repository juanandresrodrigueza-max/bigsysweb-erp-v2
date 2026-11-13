<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Tests\ErpTestCase;

// Fase 94.1: el dueño da de baja la empresa desde Suscripción, como promete la política de privacidad.
class BajaEmpresaTest extends ErpTestCase
{
    public function test_el_dueno_da_de_baja_la_empresa_escribiendo_su_nombre(): void
    {
        $this->actingAs($this->dueno)->get('/suscripcion')->assertInertia(fn($p) => $p->component('Suscripcion/Index', false)->where('soloDueno', true)->where('empresa', 'Empresa Test'));

        // Sin el nombre exacto no pasa nada.
        $this->actingAs($this->dueno)->post('/suscripcion/baja', ['motivo' => 'Cerré el negocio', 'confirmacion' => 'otra'])->assertSessionHasErrors('confirmacion');
        $this->assertTrue(Business::find($this->empresa->id)->is_active);

        $this->actingAs($this->dueno)->post('/suscripcion/baja', ['motivo' => 'Cerré el negocio', 'confirmacion' => ' empresa test '])
            ->assertRedirect('/login')->assertSessionHas('success');
        $b = Business::withTrashed()->find($this->empresa->id);
        $this->assertFalse($b->is_active);
        $this->assertNotNull($b->deleted_at);
        $this->assertStringContainsString('Cerré el negocio', $b->suspension_motivo);
        $this->assertSame('inactive', User::find($this->dueno->id)->status);
        $this->assertGuest();
    }

    public function test_un_administrador_que_no_es_dueno_no_puede(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-baja@test.com', 'password' => 'password', 'status' => 'active']);
        $admin->forceFill(['business_id' => $this->empresa->id, 'role_id' => $this->dueno->role_id, 'current_location_id' => $this->sucursal->id])->save();
        $this->actingAs($admin)->post('/suscripcion/baja', ['motivo' => 'x', 'confirmacion' => 'Empresa Test'])->assertForbidden();
        $this->assertTrue(Business::find($this->empresa->id)->is_active);
    }
}
