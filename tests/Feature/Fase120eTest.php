<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\AcuerdoPago;
use App\Models\Alerta;
use App\Models\Comprobante;
use App\Models\PlanPago;
use App\Services\Ventas\CobradorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 120.5: Bigcito cobrador. Negocia un plan de pago por WhatsApp dentro de los límites de la empresa; el acuerdo queda para aprobar.
class Fase120eTest extends ErpTestCase
{
    private array $salientes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->empresa->forceFill(['whatsapp_settings' => ['token' => 'tok', 'phone_id' => '111', 'modo' => 'manual', 'waba_id' => 'W1', 'verify_token' => 'v']])->save();
        Http::fake(function ($req) { if (str_contains($req->url(), '/messages')) $this->salientes[] = $req['text']['body'] ?? json_encode($req->data()); return Http::response(['messages' => [['id' => 'wamid.' . count($this->salientes)]]]); });
    }

    private function cobrador(array $o = []): void
    {
        $this->empresa->forceFill(['recordatorios' => array_merge($this->empresa->recordatorios ?? [], ['cobrador' => array_merge(['activo' => true, 'max_cuotas' => 3, 'interes_pct' => 0, 'cuota_minima' => 0, 'dias_primer_vto' => 10, 'aprobar' => true], $o)])])->save();
    }

    // Un cliente con una factura de $ 121.000 vencida hace 20 días.
    private function deudor(string $nombre = 'Carlos Gómez', string $cel = '3515550001'): \App\Models\Contact
    {
        $c = $this->cliente(['name' => $nombre, 'mobile' => $cel, 'cuit' => '20-' . substr(md5($nombre), 0, 0) . random_int(10000000, 99999999) . '-3']);
        $p = $this->articulo(['stock_inicial' => 100, 'price' => 1000]);
        $f = $this->factura($c, [['product_id' => $p->id, 'cantidad' => 100, 'precio_unit' => 1000]]);
        Comprobante::whereKey($f->id)->update(['fecha_vto' => today()->subDays(20)]);
        \App\Models\CuentaCorriente::where('comprobante_id', $f->id)->update(['fecha_vto' => today()->subDays(20)]);
        return $c;
    }

    private function escribe(string $texto, string $tel = '5493515550001'): array
    {
        $antes = count($this->salientes);
        $r = $this->postJson("/api/whatsapp/entrante/{$this->empresa->id}", ['telefono' => $tel, 'texto' => $texto])->assertOk()->json();
        return ['atendidos' => $r['atendidos'], 'respuesta' => implode("\n---\n", array_slice($this->salientes, $antes))];
    }

    public function test_apagado_no_se_mete_y_sin_deuda_vencida_tampoco(): void
    {
        $this->deudor();
        $this->assertFalse(CobradorService::activo($this->empresa));
        $this->assertNotContains('cobrador', $this->escribe('¿me hacés un plan de pago?')['atendidos']);
        $this->cobrador();
        // Un cliente sin nada vencido que pregunta por cuotas no entra al cobrador; tampoco quien pregunta por cuotas con tarjeta.
        $this->cliente(['name' => 'Al Día', 'mobile' => '3515550009']);
        $this->assertNotContains('cobrador', $this->escribe('quiero un plan de pago', '5493515550009')['atendidos']);
        $this->assertNotContains('cobrador', $this->escribe('¿tienen cuotas con tarjeta?')['atendidos']);
        $this->assertFalse(CobradorService::pidePlan('¿a cuánto está el cemento? ¿hay cuotas con tarjeta?'));
        $this->assertTrue(CobradorService::pidePlan('No llego a pagar todo este mes')); $this->assertTrue(CobradorService::pidePlan('PLAN'));
        $this->assertSame(0, AcuerdoPago::count());
    }

    public function test_el_cliente_negocia_dentro_de_los_limites_y_el_acuerdo_queda_para_aprobar(): void
    {
        $this->cobrador(); $c = $this->deudor();
        $r = $this->escribe('Hola, no puedo pagar todo junto, ¿me hacés un plan?');
        $this->assertContains('cobrador', $r['atendidos']);
        $this->assertStringContainsString('Hola Carlos', $r['respuesta']); $this->assertStringContainsString('$ 121.000 vencidos', $r['respuesta']); $this->assertStringContainsString('hasta 3 cuotas mensuales sin interés', $r['respuesta']);
        $this->assertStringContainsString('• 2 cuotas de $ 60.500', $r['respuesta']); $this->assertStringContainsString('• 3 cuotas de $ 40.333', $r['respuesta']); $this->assertStringContainsString('La primera vence el ' . today()->addDays(10)->format('d/m'), $r['respuesta']);

        // Pide más de lo permitido: no se lo da, le avisa al dueño y sigue ofreciendo lo que puede.
        $r = $this->escribe('¿y en 12 cuotas?');
        $this->assertStringContainsString('Lo máximo que puedo armarte yo son 3 cuotas', $r['respuesta']); $this->assertStringContainsString('pedís 12', $r['respuesta']);
        $al = Alerta::where('tipo', 'cobrador_pedido')->first(); $this->assertStringContainsString('pide 12 cuotas', $al->titulo);
        $this->assertSame(0, AcuerdoPago::count());

        // Un número suelto dentro de otra frase no es una elección de cuotas.
        $r = $this->escribe('te pago el 20 si puedo');
        $this->assertStringContainsString('No te entendí', $r['respuesta']);

        $r = $this->escribe('en tres');
        $this->assertStringContainsString('Quedaría así', $r['respuesta']); $this->assertStringContainsString('• Cuota 1: $ 40.333 el ' . today()->addDays(10)->format('d/m'), $r['respuesta']);
        $this->assertStringContainsString('• Cuota 3: $ 40.333 el ' . today()->addDays(10)->addMonths(2)->format('d/m'), $r['respuesta']); $this->assertStringContainsString('Respondé SÍ', $r['respuesta']);
        // Cambia de idea antes de confirmar.
        $r = $this->escribe('no, mejor 2 cuotas');
        $this->assertStringContainsString('• Cuota 2: $ 60.500', $r['respuesta']);
        $r = $this->escribe('Sí, dale');
        $this->assertStringContainsString('Anotado, Carlos', $r['respuesta']); $this->assertStringContainsString('lo apruebe', $r['respuesta']);

        $a = AcuerdoPago::firstOrFail();
        $this->assertSame('pendiente', $a->estado); $this->assertSame($c->id, $a->contact_id); $this->assertSame(2, $a->cuotas); $this->assertEqualsWithDelta(60500, $a->cuota, 0.01); $this->assertEqualsWithDelta(121000, $a->total, 0.01);
        $this->assertSame(today()->addDays(10)->toDateString(), $a->primer_vencimiento->toDateString()); $this->assertGreaterThanOrEqual(8, count($a->charla));
        $this->assertSame(0, PlanPago::count(), 'Hasta que no se aprueba no hay plan');
        $this->assertStringContainsString('Carlos Gómez aceptó un plan', Alerta::where('tipo', 'cobrador_acuerdo')->first()->titulo);
        // Si vuelve a preguntar, se le dice que está esperando aprobación (no se arma otro).
        $r = $this->escribe('¿y mi plan?');
        $this->assertStringContainsString('ya está anotada', $r['respuesta']); $this->assertSame(1, AcuerdoPago::count());

        // La dueña lo ve en Cobranzas, lo aprueba: se crea el plan de siempre y se le avisa al cliente.
        $props = $this->get('/clientes/cobranzas')->viewData('page')['props'];
        $this->assertCount(1, $props['acuerdos']); $this->assertSame('Carlos Gómez', $props['acuerdos'][0]['cliente']); $this->assertTrue($props['cobrador']['activo']);
        $antes = count($this->salientes);
        $this->post("/clientes/cobranzas/acuerdos/{$a->id}", ['decision' => 'aprobar'])->assertSessionHas('success');
        $plan = PlanPago::with('cuotas')->firstOrFail();
        $this->assertSame(2, $plan->cuotas_n); $this->assertEqualsWithDelta(121000, (float) $plan->total, 0.01); $this->assertSame($c->id, $plan->contact_id);
        $this->assertSame(today()->addDays(10)->toDateString(), \Carbon\Carbon::parse($plan->cuotas[0]->vencimiento)->toDateString());
        $a->refresh(); $this->assertSame('aprobado', $a->estado); $this->assertSame($plan->id, $a->plan_pago_id); $this->assertSame($this->dueno->id, $a->resuelto_por);
        $this->assertStringContainsString("aprobó tu plan de pago {$plan->numeroFormateado()}", $this->salientes[$antes]); $this->assertStringContainsString('• Cuota 2: $ 60.500', $this->salientes[$antes]);
        $this->assertNotNull(Alerta::where('tipo', 'cobrador_acuerdo')->first()->resuelta_en);
        // Dos veces no.
        $this->post("/clientes/cobranzas/acuerdos/{$a->id}", ['decision' => 'aprobar'])->assertSessionHas('error');
        $this->assertSame(1, PlanPago::count());
    }

    public function test_interes_cuota_minima_un_solo_pago_y_aprobacion_automatica(): void
    {
        // 10 % de interés, cuota mínima de $ 50.000: con $ 133.100 de total entran 2 cuotas como mucho, aunque el tope sea 6.
        $this->cobrador(['max_cuotas' => 6, 'interes_pct' => 10, 'cuota_minima' => 50000, 'aprobar' => false]); $c = $this->deudor();
        $o = app(CobradorService::class)->oferta($this->empresa->fresh(), $c);
        $this->assertEqualsWithDelta(133100, $o['total'], 0.01); $this->assertSame(2, $o['max']);
        $r = $this->escribe('quiero refinanciar');
        $this->assertStringContainsString('hasta 2 cuotas mensuales con 10 % de interés', $r['respuesta']); $this->assertStringNotContainsString('3 cuotas', $r['respuesta']);
        $this->escribe('2');
        $r = $this->escribe('si');
        // Sin aprobación previa: el plan queda armado en el momento, con su nota de débito por el interés.
        $this->assertStringContainsString('quedó confirmado tu plan PP-', $r['respuesta']);
        $plan = PlanPago::firstOrFail(); $this->assertEqualsWithDelta(133100, (float) $plan->total, 0.01); $this->assertEqualsWithDelta(12100, (float) $plan->interes, 0.01);
        $this->assertSame('aprobado', AcuerdoPago::first()->estado); $this->assertNull(AcuerdoPago::first()->resuelto_por);

        // Con tope de una cuota, es un compromiso de fecha.
        $this->cobrador(['max_cuotas' => 1]); $this->deudor('Dora Paz', '3515550002');
        $r = $this->escribe('no llego este mes, dame tiempo', '5493515550002');
        $this->assertStringContainsString('Te puedo dar hasta el ' . today()->addDays(10)->format('d/m'), $r['respuesta']);
        $r = $this->escribe('dale', '5493515550002');
        $this->assertStringContainsString('Un pago de $ 121.000', $r['respuesta']);
        $this->escribe('sí', '5493515550002');
        $this->assertSame(1, AcuerdoPago::where('estado', 'pendiente')->where('cuotas', 1)->count());
    }

    public function test_pide_una_persona_rechazo_y_si_la_deuda_ya_se_cobro(): void
    {
        $this->cobrador(); $c = $this->deudor();
        $this->escribe('necesito un plan de pago');
        $r = $this->escribe('ninguna me sirve, quiero hablar con alguien');
        $this->assertStringContainsString('Ya le avisé a Empresa Test', $r['respuesta']);
        $this->assertStringContainsString('pide hablar con alguien', Alerta::where('tipo', 'cobrador_pedido')->first()->titulo);
        // La charla se cerró: un mensaje nuevo que no habla de pagar sigue el camino de siempre.
        $this->assertNotContains('cobrador', $this->escribe('gracias')['atendidos']);

        // Arma un acuerdo y la dueña lo rechaza con un motivo: se le avisa.
        $this->escribe('plan'); $this->escribe('3 cuotas'); $this->escribe('si');
        $a = AcuerdoPago::firstOrFail(); $antes = count($this->salientes);
        $this->post("/clientes/cobranzas/acuerdos/{$a->id}", ['decision' => 'rechazar', 'motivo' => 'necesitamos al menos la mitad ahora'])->assertSessionHas('success');
        $this->assertSame('rechazado', $a->fresh()->estado); $this->assertStringContainsString('necesitamos al menos la mitad ahora', $this->salientes[$antes]); $this->assertSame(0, PlanPago::count());

        // Otro acuerdo, pero el cliente paga antes de que lo aprueben: al aprobar se cierra solo, sin plan.
        $this->escribe('plan'); $this->escribe('2'); $this->escribe('si');
        $b = AcuerdoPago::where('estado', 'pendiente')->firstOrFail();
        Comprobante::whereIn('id', $b->comprobantes)->update(['saldo' => 0]);
        $this->post("/clientes/cobranzas/acuerdos/{$b->id}", ['decision' => 'aprobar'])->assertSessionHas('error');
        $this->assertSame('rechazado', $b->fresh()->estado); $this->assertSame(0, PlanPago::count());
    }

    public function test_configurar_no_pisa_el_whatsapp_ni_los_avisos_y_el_recordatorio_invita_al_plan(): void
    {
        $this->post('/clientes/cobranzas/cobrador', ['activo' => true, 'max_cuotas' => 4, 'interes_pct' => 5, 'cuota_minima' => 20000, 'dias_primer_vto' => 7, 'aprobar' => true])->assertSessionHas('success');
        $cfg = CobradorService::config($this->empresa->fresh());
        $this->assertSame(['activo' => true, 'max_cuotas' => 4, 'interes_pct' => 5.0, 'cuota_minima' => 20000.0, 'dias_primer_vto' => 7, 'aprobar' => true], $cfg);
        $this->post('/clientes/cobranzas/cobrador', ['activo' => true, 'max_cuotas' => 40, 'dias_primer_vto' => 7])->assertSessionHasErrors('max_cuotas');
        // Guardar los avisos automáticos no borra el cobrador ni desarma la conexión de WhatsApp (modo, cuenta, verificación).
        $this->post('/clientes/cobranzas/configurar', ['activo' => true, 'ia' => false, 'dias' => [7], 'canales' => ['whatsapp'], 'texto' => 'Hola {cliente}, la {comprobante} {estado}.', 'whatsapp_token' => 'tok', 'whatsapp_phone_id' => '111'])->assertSessionHas('success');
        $b = $this->empresa->fresh();
        $this->assertTrue(CobradorService::activo($b)); $this->assertSame(4, CobradorService::config($b)['max_cuotas']);
        $this->assertSame('W1', $b->whatsapp_settings['waba_id']); $this->assertSame('manual', $b->whatsapp_settings['modo']); $this->assertSame('v', $b->whatsapp_settings['verify_token']);
        // El recordatorio por WhatsApp de algo vencido invita a responder PLAN.
        $c = $this->deudor(); $antes = count($this->salientes);
        $this->post("/clientes/cobranzas/{$c->id}/recordar", ['canal' => 'whatsapp']);
        $this->assertStringContainsString('respondé PLAN', implode("\n", array_slice($this->salientes, $antes)) . \App\Models\Envio::latest('id')->value('cuerpo'));

        // Bigcito: ver y aprobar acuerdos.
        $this->escribe('PLAN'); $this->escribe('2 cuotas'); $this->escribe('si');
        $cat = app(Catalogo::class);
        $v = $cat->llamar($this->dueno, 'acuerdos_por_aprobar', [], 'asistente');
        $this->assertTrue($v['cobrador_activo']); $this->assertCount(1, $v['pendientes']); $this->assertSame(4, $v['limites']['cuotas_maximo']); $this->assertArrayNotHasKey('charla', $v['pendientes'][0]);
        $p = $cat->llamar($this->dueno, 'resolver_acuerdo_pago', ['cliente' => 'gomez', 'decision' => 'aprobar'], 'asistente');
        $this->assertStringContainsString('Aprobar el acuerdo de Carlos Gómez', json_encode($p, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, PlanPago::count(), 'Bigcito propone; no aprueba sin el sí');
    }
}
