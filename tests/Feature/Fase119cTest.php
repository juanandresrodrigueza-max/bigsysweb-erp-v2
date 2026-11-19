<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 119.3: la charla tiene continuidad («lo mismo para García») y Bigcito puede encadenar más consultas en una misma respuesta.
class Fase119cTest extends ErpTestCase
{
    private function conIa(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-ant-test', 'modelo' => 'claude-haiku-5-5']])->save(); $this->actingAs($this->dueno->fresh());
    }

    public function test_la_propuesta_viaja_con_sus_datos_y_el_historial_los_devuelve_al_modelo(): void
    {
        $this->conIa(); $this->cliente(['name' => 'López SRL', 'balance' => 9000]); $this->cliente(['name' => 'García Hnos', 'balance' => 9000]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'registrar_cobro', 'input' => ['cliente' => 'López SRL', 'monto' => 5000, 'medio' => 'efectivo']]]])
            ->push(['content' => [['type' => 'tool_use', 'id' => 't2', 'name' => 'registrar_cobro', 'input' => ['cliente' => 'García Hnos', 'monto' => 5000, 'medio' => 'efectivo']]]])]);
        $r = $this->postJson('/agente/chat', ['mensaje' => 'cobrale 5000 a López en efectivo'])->assertOk()->json();
        $this->assertSame('registrar_cobro', $r['propuesta']['accion']); $this->assertSame(['cliente' => 'López SRL', 'monto' => 5000, 'medio' => 'efectivo'], $r['propuesta']['args']);

        // El panel manda el historial con la propuesta y sus datos; el modelo lo recibe y arma «lo mismo» para otro cliente.
        $hist = [['rol' => 'user', 'texto' => 'cobrale 5000 a López en efectivo'], ['rol' => 'assistant', 'texto' => 'Te propongo esto. ¿Confirmás? [propuesta registrar_cobro {"cliente":"López SRL","monto":5000,"medio":"efectivo"}: ' . $r['propuesta']['titulo'] . ' → hecho]']];
        $r2 = $this->postJson('/agente/chat', ['mensaje' => 'lo mismo para García', 'historial' => $hist])->assertOk()->json();
        $this->assertStringContainsString('García', $r2['propuesta']['titulo']); $this->assertSame('García Hnos', $r2['propuesta']['args']['cliente']);
        Http::assertSent(fn($req) => str_contains($req['system'], 'La charla tiene memoria') && str_contains(json_encode($req['messages'], JSON_UNESCAPED_UNICODE), '[propuesta registrar_cobro'));
    }

    public function test_puede_encadenar_hasta_cinco_rondas_de_consultas(): void
    {
        $this->conIa(); $this->cliente(['name' => 'López SRL']);
        $consulta = fn($id) => ['content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'resumen_negocio', 'input' => []]]];
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($consulta('a'))->push($consulta('b'))->push($consulta('c'))->push($consulta('d'))->push(['content' => [['type' => 'text', 'text' => 'Listo, revisé todo.']]])]);
        $this->postJson('/agente/chat', ['mensaje' => 'revisá todo'])->assertOk()->assertJsonPath('respuesta', 'Listo, revisé todo.');
        Http::assertSentCount(5);
    }
}
