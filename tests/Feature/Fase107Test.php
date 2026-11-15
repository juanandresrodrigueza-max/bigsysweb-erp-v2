<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\AuditLog;
use App\Models\Envio;
use App\Models\ExpenseCategory;
use App\Models\OrdenCompra;
use App\Models\PrecioHistorial;
use App\Models\Propuesta;
use App\Services\Agentes\AgentesService;
use App\Services\Agentes\CobradorAgente;
use App\Services\Agentes\ControlInternoAgente;
use App\Services\Agentes\PreciosAgente;
use App\Services\Agentes\ResumenHabladoService;
use App\Services\Fondos\FondosService;
use Illuminate\Support\Facades\Auth;
use Tests\ErpTestCase;

// Fase 107: Bigcito trabaja de noche. Cinco agentes dejan propuestas (cobrador, reposición, precios, cierre, control interno); el dueño
// aprueba de a un toque y recién ahí se ejecuta. Más el resumen hablado y el padrón automático.
class Fase107Test extends ErpTestCase
{
    private function vencida($cli, float $total, int $diasVencida): \App\Models\Comprobante
    {
        $p = $this->articulo(['price' => $total, 'cost' => $total / 2]);
        $c = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => $total, 'alicuota_iva' => 0]]);
        $c->forceFill(['fecha_vto' => today()->subDays($diasVencida)])->save();
        return $c;
    }

    public function test_el_cobrador_propone_a_quien_escribir_y_aprobar_manda_el_recordatorio(): void
    {
        $cli = $this->cliente(['name' => 'Ferretería López', 'mobile' => '351 555 1234', 'email' => null]);
        $this->vencida($cli, 50000, 40); $this->vencida($cli, 30000, 8);
        $alDia = $this->cliente(['name' => 'Al día SA', 'cuit' => '30-71234567-8', 'mobile' => '351 555 0000']);
        $this->factura($alDia, [['product_id' => $this->articulo()->id, 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 0]]);
        $svc = app(AgentesService::class);

        $r = $svc->correr($this->empresa, ['cobrador']);
        $this->assertSame(['cobrador' => 1], $r);
        $p = Propuesta::pendientes()->first();
        $this->assertStringContainsString('Ferretería López', $p->titulo);
        $this->assertSame('whatsapp', $p->datos['canal']);
        $this->assertCount(2, $p->datos['facturas']);
        $this->assertStringContainsString('80.000', str_replace('$ ', '', $p->datos['texto']));
        $this->assertSame(1, $p->prioridad); // 40 días de mora
        // Segunda corrida: no duplica.
        $this->assertSame(['cobrador' => 0], $svc->correr($this->empresa, ['cobrador']));
        $this->assertSame(1, Propuesta::count());

        // Aprobar desde la pantalla: queda el envío (sin API de WhatsApp, el link listo) y la propuesta aprobada con el resultado.
        $this->get('/propuestas')->assertOk()->assertInertia(fn($x) => $x->component('Propuestas/Index', false)->has('pendientes', 1)->where('pendientes.0.agente', 'cobrador')->where('puedeResolver', true));
        $this->post("/propuestas/{$p->id}/resolver", ['aprobar' => true])->assertSessionHas('success');
        $p->refresh();
        $this->assertSame('aprobada', $p->estado);
        $this->assertStringContainsString('wa.me', $p->resultado);
        $e = Envio::where('contact_id', $cli->id)->where('tipo', 'recordatorio')->first();
        $this->assertNotNull($e); $this->assertStringContainsString('80.000', $e->mensaje ?? $e->link ?? '');
        // Recién avisado: no vuelve a proponerlo por 5 días.
        $this->assertSame(['cobrador' => 0], $svc->correr($this->empresa, ['cobrador']));
        $this->travel(6)->days();
        $this->assertSame(['cobrador' => 1], $svc->correr($this->empresa, ['cobrador']));
    }

    public function test_rechazar_no_insiste_por_una_semana_y_el_dashboard_muestra_el_banner(): void
    {
        $cli = $this->cliente(['mobile' => '351 555 1234']); $this->vencida($cli, 10000, 10);
        $svc = app(AgentesService::class); $svc->correr($this->empresa, ['cobrador']);
        $this->get('/dashboard')->assertOk()->assertInertia(fn($x) => $x->where('propuestas', 1));
        $p = Propuesta::pendientes()->first();
        $this->post("/propuestas/{$p->id}/resolver", ['aprobar' => false, 'motivo' => 'Ya hablé con él'])->assertSessionHas('info');
        $this->assertSame('rechazada', $p->fresh()->estado);
        $this->assertSame(['cobrador' => 0], $svc->correr($this->empresa, ['cobrador']));
        $this->travel(8)->days();
        $this->assertSame(['cobrador' => 1], $svc->correr($this->empresa, ['cobrador']));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('accion', 'propuesta_rechazada')->exists());
    }

    public function test_la_reposicion_arma_una_orden_por_proveedor_y_aprobar_la_crea_en_borrador(): void
    {
        $prov = $this->proveedor(['name' => 'Distribuidora Norte']);
        $a = $this->articulo(['name' => 'Tornillos', 'stock_min' => 10, 'stock_inicial' => 2, 'cost' => 100, 'proveedor_id' => $prov->id]);
        $b = $this->articulo(['name' => 'Tuercas', 'stock_min' => 5, 'stock_inicial' => 0, 'cost' => 50, 'proveedor_id' => $prov->id]);
        $this->articulo(['name' => 'Sin proveedor', 'stock_min' => 3, 'stock_inicial' => 1, 'cost' => 10]);
        $this->articulo(['name' => 'Con stock', 'stock_min' => 3, 'stock_inicial' => 30]);
        $svc = app(AgentesService::class);
        $this->assertSame(['reposicion' => 2], $svc->correr($this->empresa, ['reposicion']));
        $p = Propuesta::pendientes()->where('clave', "reponer-{$prov->id}")->firstOrFail();
        $this->assertStringContainsString('Distribuidora Norte', $p->titulo);
        $this->assertSame(1, $p->prioridad); // hay uno sin stock
        $this->assertEquals(18 * 100 + 10 * 50, (float) $p->importe); // (2*10-2)*100 + (2*5-0)*50
        $aviso = Propuesta::pendientes()->where('clave', 'sin-proveedor')->firstOrFail();
        $this->assertTrue($aviso->soloAviso());

        $svc->resolver($p, $this->dueno, true);
        $oc = OrdenCompra::where('contact_id', $prov->id)->first();
        $this->assertNotNull($oc); $this->assertSame('borrador', $oc->estado); $this->assertSame(2, $oc->items()->count());
        $this->assertEquals(18, (float) $oc->items()->where('product_id', $a->id)->value('cantidad'));
        // Con la orden abierta no vuelve a proponer ese proveedor.
        $this->assertSame(['reposicion' => 0], $svc->correr($this->empresa, ['reposicion']));
    }

    public function test_precios_propone_el_precio_nuevo_cuando_sube_el_costo_respetando_el_margen(): void
    {
        $p = $this->articulo(['name' => 'Cemento', 'price' => 1000, 'cost' => 600]); // margen 66,67 %
        $otro = $this->articulo(['name' => 'Con cadena', 'price' => 1000, 'cost' => 600, 'margenes' => [1 => 50]]); // se recalcula solo: no entra
        $p->update(['cost' => 800]); $otro->update(['cost' => 800]);
        $this->assertSame(1, PrecioHistorial::where('product_id', $p->id)->count());
        $svc = app(AgentesService::class);
        $this->assertSame(['precios' => 1], $svc->correr($this->empresa, ['precios']));
        $prop = Propuesta::pendientes()->firstOrFail();
        $this->assertSame(1, count($prop->datos['items']));
        $it = $prop->datos['items'][0];
        $this->assertSame('Cemento', $it['nombre']);
        $this->assertEquals(1350.0, $it['precio_nuevo']); // 800 × 1,6667 = 1333 → redondeo a 50
        $this->assertEquals(66.7, $it['margen']);
        $this->assertSame(1350.0, PreciosAgente::redondear(1333.3)); $this->assertSame(130.0, PreciosAgente::redondear(121.0)); $this->assertSame(12400.0, PreciosAgente::redondear(12301.0));
        $svc->resolver($prop, $this->dueno, true);
        $this->assertEquals(1350, (float) $p->fresh()->price);
        // Bajo el mínimo general: propone llevarlo al mínimo.
        $q = $this->articulo(['name' => 'Flojo', 'price' => 1050, 'cost' => 1000]);
        $this->empresa->update(['rentabilidad' => ['margen_minimo' => 30]]);
        $this->assertSame(['precios' => 1], $svc->correr($this->empresa, ['precios']));
        $bajo = Propuesta::pendientes()->where('clave', 'bajo-minimo')->firstOrFail();
        $this->assertEquals(1300.0, collect($bajo->datos['items'])->firstWhere('id', $q->id)['precio_nuevo']);
    }

    public function test_el_cierre_propone_asientos_y_categorias_del_mes_anterior(): void
    {
        $mesPasado = today()->subMonthNoOverflow()->startOfMonth()->addDays(10);
        $comb = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Combustible']);
        $fondos = app(FondosService::class);
        $fondos->registrar($this->caja, ['origen' => 'gasto', 'concepto' => 'Nafta 12/09', 'egreso' => 20000, 'fecha' => $mesPasado->copy()->subMonth()->toDateString(), 'expense_category_id' => $comb->id]);
        $sin = $fondos->registrar($this->caja, ['origen' => 'gasto', 'concepto' => 'Nafta 15/10', 'egreso' => 25000, 'fecha' => $mesPasado->toDateString()]);
        $raro = $fondos->registrar($this->caja, ['origen' => 'gasto', 'concepto' => 'Zzz desconocido', 'egreso' => 500, 'fecha' => $mesPasado->toDateString()]);
        $c = $this->factura($this->cliente(), [['product_id' => $this->articulo()->id, 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]], ['fecha' => $mesPasado->toDateString()]);
        \App\Models\Asiento::where('origen_id', $c->id)->where('origen', 'venta')->delete();

        // Corre a pedido aunque no sean los primeros días del mes.
        $this->post('/propuestas/correr', ['agente' => 'cierre'])->assertSessionHas('success');
        $claves = Propuesta::pendientes()->pluck('clave')->all();
        $mes = $mesPasado->format('Y-m');
        $this->assertContains("asientos-{$mes}", $claves); $this->assertContains("categorias-{$mes}", $claves); $this->assertContains("sin-categoria-{$mes}", $claves);
        $cat = Propuesta::where('clave', "categorias-{$mes}")->first();
        $this->assertSame('Combustible', $cat->datos['items'][0]['categoria']);
        $this->assertSame($sin->id, $cat->datos['items'][0]['id']);
        $svc = app(AgentesService::class);
        $svc->resolver($cat, $this->dueno, true);
        $this->assertSame($comb->id, $sin->fresh()->expense_category_id);
        $this->assertNull($raro->fresh()->expense_category_id);
        $svc->resolver(Propuesta::where('clave', "asientos-{$mes}")->first(), $this->dueno, true);
        $this->assertTrue(\App\Models\Asiento::where('origen_id', $c->id)->where('origen', 'venta')->exists());
    }

    public function test_control_interno_detecta_al_usuario_que_se_sale_de_lo_normal(): void
    {
        $vend = $this->usuarioConRol('vendedor'); $cli = $this->cliente(); $art = $this->articulo(['price' => 1000, 'cost' => 500]);
        $it = [['product_id' => $art->id, 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]];
        for ($i = 0; $i < 10; $i++) $this->factura($cli, $it);
        Auth::login($vend); $this->actingAs($vend);
        $fs = []; for ($i = 0; $i < 10; $i++) $fs[] = $this->factura($cli, $it);
        foreach (array_slice($fs, 0, 5) as $f) AuditLog::registrar('anular', $f, 'Anulada para la prueba');
        Auth::login($this->dueno); $this->actingAs($this->dueno);

        $a = app(ControlInternoAgente::class)->analizar($this->empresa);
        $this->assertCount(2, $a['usuarios']);
        $this->assertCount(1, $a['hallazgos']);
        $this->assertSame($vend->id, $a['hallazgos'][0]['id']);
        $this->assertSame('anula', $a['hallazgos'][0]['motivos'][0]['que']);
        $svc = app(AgentesService::class);
        $this->assertSame(['control' => 1], $svc->correr($this->empresa, ['control']));
        $p = Propuesta::pendientes()->firstOrFail();
        $this->assertTrue($p->soloAviso()); $this->assertStringContainsString($vend->name, $p->titulo);
        $svc->resolver($p, $this->dueno, true);
        $this->assertSame('Visto.', $p->fresh()->resultado);
        // Bigcito: sólo el dueño.
        $r = app(Catalogo::class)->llamar($this->dueno, 'control_interno', [], 'asistente');
        $this->assertCount(1, $r['se_salen_de_lo_normal']);
        $this->assertArrayHasKey('error', app(Catalogo::class)->llamar($vend, 'control_interno', [], 'asistente'));
    }

    public function test_resumen_hablado_configuracion_comando_y_bigcito(): void
    {
        $cli = $this->cliente(['mobile' => '351 555 1234']); $this->vencida($cli, 1500, 10);
        $this->artisan('agentes:correr')->expectsOutputToContain('Propuestas nuevas: 1')->assertSuccessful();
        $this->get('/resumen-hablado')->assertOk()->assertInertia(fn($x) => $x->component('Propuestas/ResumenHablado', false)->where('propuestas', 1));
        $voz = ResumenHabladoService::paraVoz("*Ventas:* $ 1.500 en 2 comprobantes (+10% vs ayer)\n⚠️ Vencido: $ 1.500,50");
        $this->assertStringNotContainsString('*', $voz); $this->assertStringNotContainsString('⚠', $voz);
        $this->assertStringContainsString('1500 pesos', $voz); $this->assertStringContainsString('10 por ciento', $voz); $this->assertStringContainsString('50 centavos', $voz);
        // Bigcito: lista, resuelve y corre.
        $cat = app(Catalogo::class);
        $r = $cat->llamar($this->dueno, 'propuestas_de_bigcito', [], 'asistente');
        $this->assertSame(1, $r['pendientes']); $num = $r['propuestas'][0]['numero'];
        $prop = $cat->llamar($this->dueno, 'resolver_propuesta', ['numero' => $num, 'decision' => 'rechazar', 'motivo' => 'no'], 'asistente');
        $this->assertArrayHasKey('propuesta_id', $prop);
        $cat->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']], 'asistente');
        $this->assertSame('rechazada', Propuesta::find($num)->estado);
        $this->assertStringContainsString('pesos', $cat->llamar($this->dueno, 'resumen_hablado', [], 'asistente')['para_decir']);
        // Configuración: apagar el cobrador.
        $this->post('/propuestas/configurar', ['activos' => ['reposicion'], 'push' => false])->assertSessionHas('success');
        $this->assertSame(['reposicion'], app(AgentesService::class)->config($this->empresa->fresh())['activos']);
        $this->artisan('agentes:correr')->expectsOutputToContain('Propuestas nuevas: 0')->assertSuccessful();
    }
}
