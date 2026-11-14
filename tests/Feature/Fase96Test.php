<?php

namespace Tests\Feature;

use App\Mail\DocumentoMail;
use App\Models\Campana;
use App\Models\CentroCosto;
use App\Models\Comprobante;
use App\Models\CuentaFondos;
use App\Models\Envio;
use App\Models\SistemaConfig;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\ErpTestCase;

// Fase 96 · Lo que faltaba de Contabilium: centros de costo con presupuesto contra real, mensajes a clientes, entrar con Google
// y el atajo para aprobar presupuestos desde el portal.
class Fase96Test extends ErpTestCase
{
    public function test_centros_de_costo_presupuesto_y_real_desde_gastos_y_compras(): void
    {
        $this->post('/contable/centros', ['nombre' => 'Depósito', 'codigo' => 'DEP'])->assertSessionHas('success');
        $this->post('/contable/centros', ['nombre' => 'Administración']);
        $dep = CentroCosto::where('nombre', 'Depósito')->first();
        $anio = (int) today()->format('Y'); $mes = (int) today()->format('n');
        $this->post('/contable/centros/presupuesto', ['centro_costo_id' => $dep->id, 'anio' => $anio, 'montos' => array_fill(1, 12, 100000)])->assertSessionHas('success');

        // Un gasto de Plata imputado al depósito y una compra también.
        $caja = $this->caja ?? CuentaFondos::first();
        $this->post('/fondos/movimiento', ['cuenta_fondos_id' => $caja->id, 'fecha' => today()->toDateString(), 'tipo' => 'egreso', 'monto' => 30000, 'concepto' => 'Pallets', 'centro_costo_id' => $dep->id])->assertSessionHas('success');
        $prov = $this->proveedor(); $art = $this->articulo(['name' => 'Cinta', 'price' => 100, 'cost' => 80, 'iva' => 21]);
        $this->post('/proveedores/compras', ['contact_id' => $prov->id, 'tipo' => 'FA', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'centro_costo_id' => $dep->id, 'items' => [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000, 'alicuota_iva' => 21]]]);
        $compra = Comprobante::compras()->latest('id')->first();
        $this->assertSame($dep->id, $compra->centro_costo_id);
        if ($compra->estado !== 'emitido') $this->post("/proveedores/compras/{$compra->id}/confirmar");
        $compra->refresh();

        $t = $this->get("/contable/centros?anio={$anio}")->assertOk()->viewData('page')['props']['tablero'];
        $fila = collect($t['filas'])->firstWhere('nombre', 'Depósito'); $m = collect($fila['meses'])->firstWhere('mes', $mes);
        $this->assertEquals(100000, $m['presupuesto']);
        $this->assertEqualsWithDelta(30000 + ($compra->estado === 'emitido' ? (float) $compra->total : 0), $m['real'], 0.01, 'El real suma el gasto y la compra del centro. Compra: ' . $compra->estado . ' $' . $compra->total . ' · movimientos: ' . \App\Models\MovimientoFondos::where('centro_costo_id', $dep->id)->count());
        $this->assertSame(1200000.0, (float) $fila['total_presupuesto']);
        $this->assertNotNull(collect($t['filas'])->firstWhere('nombre', 'Administración'));
        // Bigcito: "¿cómo vamos contra el presupuesto?"
        $r = app(\App\Mcp\Catalogo::class)->llamar($this->dueno, 'presupuesto_vs_real', ['anio' => $anio], 'asistente');
        $this->assertSame($anio, $r['anio']); $this->assertNotEmpty($r['centros']);
        // Inactivar no borra lo imputado.
        $this->delete("/contable/centros/{$dep->id}")->assertSessionHas('success');
        $this->assertFalse($dep->fresh()->activo); $this->assertSame($dep->id, $compra->fresh()->centro_costo_id);
    }

    public function test_mensaje_a_clientes_por_mail_y_whatsapp_con_variables(): void
    {
        Mail::fake();
        $a = $this->cliente(['name' => 'Ana', 'email' => 'ana@test.com', 'mobile' => '3515550001', 'balance' => 1500]);
        $b = $this->cliente(['name' => 'Beto', 'cuit' => '20-11111111-1', 'email' => null, 'mobile' => '3515550002']);
        $c = $this->cliente(['name' => 'Caro', 'cuit' => '20-22222222-2', 'email' => 'caro@test.com', 'mobile' => null, 'balance' => 0]);
        $this->get('/clientes/campanas')->assertOk()->assertInertia(fn($p) => $p->component('Clientes/Campanas', false)->where('conteo.mail', 2)->where('conteo.whatsapp', 2));
        $this->getJson('/clientes/campanas/contar?estado=deudores')->assertOk()->assertJson(['mail' => 1, 'whatsapp' => 1]);

        // Mail: sale a los dos con email, personalizado.
        $this->post('/clientes/campanas', ['canal' => 'mail', 'texto' => 'Hola {cliente}, tu saldo es {saldo}.'])->assertSessionHasErrors('asunto');
        $this->post('/clientes/campanas', ['canal' => 'mail', 'asunto' => 'Nueva lista', 'texto' => 'Hola {cliente}, tu saldo es {saldo}.'])->assertRedirect('/clientes/campanas');
        $camp = Campana::latest('id')->first();
        $this->assertSame(2, $camp->destinatarios); $this->assertSame(2, $camp->fresh()->enviados); $this->assertNotNull($camp->fresh()->terminada_en);
        Mail::assertSent(DocumentoMail::class, fn($m) => str_contains($m->cuerpo, 'Hola Ana') && str_contains($m->cuerpo, '1.500,00') && $m->asunto === 'Nueva lista');
        Mail::assertSent(DocumentoMail::class, 2);
        $this->assertSame(2, Envio::where('campana_id', $camp->id)->where('tipo', 'campana')->count());

        // WhatsApp sin API: quedan los links; al tocarlos se marcan enviados.
        $this->post('/clientes/campanas', ['canal' => 'whatsapp', 'texto' => 'Cerramos el viernes, {cliente}.'])->assertRedirect('/clientes/campanas');
        $wa = Campana::latest('id')->first();
        $this->assertSame(2, $wa->destinatarios); $this->assertSame(2, $wa->fresh()->pendientes);
        $lista = $this->get('/clientes/campanas')->viewData('page')['props']['campanas'];
        $fila = collect($lista)->firstWhere('id', $wa->id);
        $this->assertCount(2, $fila['para_mandar']); $this->assertStringContainsString('wa.me/543515550001', $fila['para_mandar'][0]['link']); $this->assertStringContainsString('Ana', urldecode($fila['para_mandar'][0]['link']));
        $this->post("/clientes/campanas/envios/{$fila['para_mandar'][0]['id']}/enviado");
        $this->assertSame(1, $wa->fresh()->enviados);

        // Bigcito: "avisale a los que deben".
        $cat = app(\App\Mcp\Catalogo::class);
        $p = $cat->llamar($this->dueno->fresh(), 'mandar_mensaje_clientes', ['texto' => 'Hola {cliente}, te esperamos.', 'a' => 'deudores'], 'asistente');
        $this->assertStringContainsString('1 cliente', $p['titulo']);
        $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
        $this->assertSame(1, Campana::latest('id')->first()->destinatarios);
    }

    public function test_entrar_con_google_solo_para_usuarios_existentes(): void
    {
        auth()->logout();
        $this->get('/login')->assertInertia(fn($p) => $p->where('google', false));
        $this->get('/login/google')->assertNotFound();
        SistemaConfig::set('google_client_id', 'cid.apps.googleusercontent.com'); SistemaConfig::set('google_client_secret', 'secreto');
        auth()->logout();
        $this->get('/login')->assertInertia(fn($p) => $p->where('google', true));
        $r = $this->get('/login/google'); $r->assertRedirect(); $this->assertStringContainsString('accounts.google.com', $r->headers->get('Location')); $this->assertStringContainsString('client_id=cid.apps.googleusercontent.com', $r->headers->get('Location'));
        $state = session('google_state'); $this->assertNotEmpty($state);

        // Estado inválido: afuera.
        $this->get('/login/google/volver?code=abc&state=otro')->assertRedirect('/login')->assertSessionHasErrors('email'); $this->assertGuest();
        // Email desconocido: afuera, sin crear cuentas.
        $emailGoogle = 'nadie@test.com';
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'openidconnect.googleapis.com/*' => function () use (&$emailGoogle) { return Http::response(['email' => $emailGoogle, 'email_verified' => true]); }]);
        $this->withSession(['google_state' => 's1'])->get('/login/google/volver?code=abc&state=s1')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest(); $this->assertNull(User::where('email', 'nadie@test.com')->first());
        // Email del dueño: entra.
        $emailGoogle = strtoupper($this->dueno->email);
        $this->withSession(['google_state' => 's2'])->get('/login/google/volver?code=abc&state=s2')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->dueno);
    }

    public function test_portal_ofrece_aprobar_el_presupuesto_pendiente(): void
    {
        $cli = $this->cliente(); $art = $this->articulo(['price' => 1000]);
        $pre = $this->factura($cli, [['product_id' => $art->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['tipo' => 'PRE']);
        $this->assertSame('PRE', $pre->tipo);
        $cli->refresh(); if (! $cli->portal_token) { $cli->forceFill(['portal_token' => 'tok-portal-96'])->save(); }
        auth()->logout();
        $this->get('/portal/' . $cli->portal_token)->assertOk()->assertSee('Aprobar presupuesto')->assertSee('data-aprobar="' . $pre->id . '"', false);
        $this->post('/p/' . $pre->tokenPublico() . '/responder', ['respuesta' => 'aprobar'])->assertRedirect();
        $this->get('/portal/' . $cli->portal_token)->assertOk()->assertSee('Aprobado ✓')->assertDontSee('Aprobar presupuesto');
    }
}
