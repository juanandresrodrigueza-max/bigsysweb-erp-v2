<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ConjuntoEmpresas;
use App\Models\Role;
use App\Models\User;
use App\Services\Empresas\EstudioService;
use App\Services\Empresas\GrupoService;
use Tests\ErpTestCase;

// Fase 39: perfiles listos y el mensaje de lo que falta; empresas por persona validadas en el servidor y conjuntos con ventas entre empresas;
// el estudio contable con precio progresivo, semáforo y vencimientos por CUIT.
class MultiEmpresaTest extends ErpTestCase
{
    private function segunda(string $nombre = 'Empresa Dos', string $cuit = '30-71000000-9'): Business
    {
        [$b] = $this->crearEmpresa($nombre, \Illuminate\Support\Str::slug($nombre));
        $b->update(['cuit' => $cuit, 'owner_id' => $this->dueno->id]);
        $this->dueno->empresas()->syncWithoutDetaching([$b->id => ['role_id' => Role::withoutGlobalScopes()->where('business_id', $b->id)->where('slug', 'dueno')->value('id')]]);
        return $b->fresh();
    }

    public function test_perfiles_listos_inicio_segun_permisos_y_mensaje_de_lo_que_falta(): void
    {
        foreach (['admin_ventas', 'solo_mirar', 'administrador', 'vendedor', 'deposito'] as $slug) $this->assertNotNull($this->empresa->roles()->where('slug', $slug)->first(), "falta el perfil {$slug}");
        $this->get('/configuracion/usuarios')->assertInertia(fn($p) => $p->component('Configuracion/Usuarios', false)->where('perfiles', fn($ps) => collect($ps)->pluck('slug')->contains('solo_mirar') && collect($ps)->pluck('slug')->contains('admin_ventas')));
        $mira = $this->usuarioConRol('solo_mirar', 'mira@test.com');
        $this->assertTrue($mira->puede('stock')); $this->assertFalse($mira->puede('stock', 'editar'));
        $r = $this->actingAs($mira)->post('/stock/precios/masivo', ['sel' => [], 'como' => []]);
        $r->assertStatus(403); $this->assertStringContainsString('Te falta el permiso «Editar» en «Stock»', $r->exception->getMessage()); $this->assertStringContainsString('Pedíselo a', $r->exception->getMessage());
        // El Inicio de un depósito no muestra ventas ni plata
        $dep = $this->usuarioConRol('deposito', 'dep@test.com');
        $this->actingAs($dep)->get('/dashboard')->assertInertia(fn($p) => $p->where('kpis', fn($k) => collect($k)->pluck('key')->all() === ['stock'])->where('ultimas', []));
    }

    public function test_empresas_por_persona_validadas_y_el_cambio_de_empresa_respeta_el_acceso(): void
    {
        $dos = $this->segunda(); $tres = $this->segunda('Empresa Tres', '30-72000000-5');
        $ana = $this->usuarioConRol('vendedor', 'ana@test.com');
        $this->post("/configuracion/usuarios/{$ana->id}/empresas", ['empresas' => [['business_id' => $dos->id, 'perfil' => 'solo_mirar']]])->assertSessionHas('success');
        $this->assertSame([$dos->id], $ana->empresas()->pluck('businesses.id')->all());
        $this->assertSame('solo_mirar', Role::withoutGlobalScopes()->find($ana->empresas()->first()->pivot->role_id)->slug);
        // Una empresa donde no manda: no
        [$ajena] = $this->crearEmpresa('Ajena', 'ajena');
        $this->post("/configuracion/usuarios/{$ana->id}/empresas", ['empresas' => [['business_id' => $ajena->id, 'perfil' => 'solo_mirar']]])->assertSessionHasErrors('empresas');
        // Ana entra a Dos pero no a Tres (el servidor lo valida)
        $this->actingAs($ana)->post("/empresa/{$tres->id}")->assertStatus(403);
        $this->actingAs($ana)->post("/empresa/{$dos->id}")->assertRedirect(); $this->assertSame($dos->id, $ana->fresh()->business_id);
        $this->assertFalse($ana->fresh()->puede('comprobantes', 'crear'), 'en Dos sólo mira');
        // Vuelve a su empresa y le sacamos Dos
        $this->actingAs($ana->fresh())->post("/empresa/{$this->empresa->id}")->assertRedirect();
        $this->actingAs($this->dueno)->post("/configuracion/usuarios/{$ana->id}/empresas", ['empresas' => []]); $this->assertNotContains($dos->id, $ana->empresas()->pluck('businesses.id')->all()); $this->actingAs($ana->fresh())->post("/empresa/{$dos->id}")->assertStatus(403);
    }

    public function test_conjunto_suma_saca_ventas_entre_empresas_por_cuit_y_avisa_atrasadas(): void
    {
        $this->empresa->update(['cuit' => '30-70000000-1']);
        $dos = $this->segunda();
        // Empresa Test le vende a Empresa Dos (cliente con su CUIT) y a un tercero
        $this->factura($this->cliente(['name' => 'Empresa Dos SA', 'cuit' => '30-71000000-9']), [['descripcion' => 'Mercadería', 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);
        $this->factura($this->cliente(['name' => 'Tercero', 'cuit' => '20-11111111-1']), [['descripcion' => 'Mercadería', 'cantidad' => 1, 'precio_unit' => 500, 'alicuota_iva' => 21]]);
        $this->post('/conjuntos', ['nombre' => 'Todo el grupo', 'empresas' => [$this->empresa->id]])->assertSessionHasErrors('empresas');
        $this->post('/conjuntos', ['nombre' => 'Todo el grupo', 'empresas' => [$this->empresa->id, $dos->id]])->assertRedirect();
        $c = ConjuntoEmpresas::first();
        $t = app(GrupoService::class)->tablero($this->dueno->fresh(), $c, today()->format('Y-m'));
        $this->assertEquals(1815, $t['total']['ventas']); $this->assertEquals(1210, $t['total']['entre_grupo']); $this->assertEquals(605, $t['total']['ventas_afuera']);
        $this->assertSame('Empresa Dos', $t['entre'][0]['a']);
        $this->assertStringContainsString('nunca cargó ventas', collect($t['atrasadas'])->firstWhere('nombre', 'Empresa Dos')['texto']);
        $this->get("/conjuntos/{$c->id}")->assertOk()->assertInertia(fn($p) => $p->component('Empresas/Conjunto', false)->has('empresas', 2));
        $otro = $this->usuarioConRol('vendedor', 'otro@test.com'); $this->actingAs($otro)->get("/conjuntos/{$c->id}")->assertStatus(404);
    }

    public function test_estudio_precio_progresivo_semaforo_y_vencimientos_por_cuit(): void
    {
        $e = app(EstudioService::class);
        $p = $e->precio(23, EstudioService::TRAMOS);
        $this->assertEquals(5 * 15000 + 15 * 11000 + 3 * 8000, $p['total']); $this->assertCount(3, $p['detalle']);
        $this->assertEquals(2 * 15000, $e->precio(2, EstudioService::TRAMOS)['total']);
        $dos = $this->segunda('Cliente Del Estudio', '30-71000000-4');
        \App\Models\Empleado::create(['business_id' => $dos->id, 'legajo' => 1, 'nombre' => 'Empleado', 'sueldo_basico' => 1, 'modalidad' => 'mensual', 'activo' => true, 'fecha_ingreso' => '2020-01-01']);
        $s = $e->semaforo($dos, '2026-10');
        $this->assertSame('rojo', $s['nivel']); $this->assertStringContainsString('sueldos de 2026-10 sin liquidar', collect($s['falta'])->pluck('texto')->implode(' | '));
        $v = $e->vencimientos(collect([$dos]), '2026-10', EstudioService::VENCIMIENTOS);
        $this->assertSame('4', $v[0]['terminacion']); $this->assertSame('2026-11-20', $v[0]['fecha']);
        $this->post('/contador/estudio', ['es_estudio' => true, 'marca' => 'Estudio Pérez & Asoc.', 'tramos' => [['hasta' => 10, 'precio' => 20000], ['hasta' => null, 'precio' => 12000]], 'vencimientos' => EstudioService::VENCIMIENTOS])->assertSessionHas('success');
        $this->get('/contador/empresas?mes=2026-10')->assertOk()->assertInertia(fn($pg) => $pg->component('Contable/MisEmpresas', false)->where('estudio.marca', 'Estudio Pérez & Asoc.')->where('precio.empresas', 1)->where('precio.total', 20000)->where('empresas.0.semaforo.nivel', 'rojo')->where('vencimientos', fn($v) => collect($v)->pluck('empresa')->contains('Cliente Del Estudio')));
    }
}
