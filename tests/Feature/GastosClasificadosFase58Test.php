<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\ExpenseCategory;
use App\Models\MovimientoFondos;
use App\Services\Contabilidad\BancoCajonesService;
use App\Services\Contabilidad\ClasificadorGastosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 58: cada gasto cae en su categoría solo (aprendido → reglas → IA), en Fondos, en el extracto del banco y por Bigcito.
class GastosClasificadosFase58Test extends ErpTestCase
{
    public function test_gasto_manual_sin_categoria_cae_en_su_categoria_por_regla_y_lo_elegido_se_aprende(): void
    {
        $this->post('/fondos/movimiento', ['cuenta_fondos_id' => $this->caja->id, 'fecha' => '2026-11-03', 'tipo' => 'egreso', 'monto' => 18500, 'concepto' => 'Factura Edenor noviembre'])
            ->assertSessionHas('success', fn($m) => str_contains($m, 'Categoría: Luz'));
        $m = MovimientoFondos::where('concepto', 'Factura Edenor noviembre')->first();
        $this->assertSame('Luz', $m->categoria->name);
        $this->assertSame(1, ExpenseCategory::where('name', 'Luz')->count());

        // Elige una categoría a mano para un concepto que no tiene regla: queda aprendido para la próxima vez.
        $cat = ExpenseCategory::create(['business_id' => $this->empresa->id, 'name' => 'Herramientas', 'color' => '#333']);
        $this->post('/fondos/movimiento', ['cuenta_fondos_id' => $this->caja->id, 'fecha' => '2026-11-03', 'tipo' => 'egreso', 'monto' => 9000, 'concepto' => 'Amoladora Bosch 710', 'expense_category_id' => $cat->id])->assertSessionHasNoErrors();
        $s = app(ClasificadorGastosService::class)->sugerir($this->empresa->fresh(), 'Amoladora Bosch 850 reemplazo');
        $this->assertSame($cat->id, $s['expense_category_id']); $this->assertSame('aprendido', $s['origen']);

        // El endpoint que sugiere mientras escribe.
        $this->getJson('/fondos/sugerir-categoria?concepto=Carga nafta YPF')->assertOk()->assertJsonPath('nombre', 'Combustible')->assertJsonPath('origen', 'regla');
        $this->getJson('/fondos/sugerir-categoria?concepto=Cosa rara')->assertOk()->assertJsonPath('expense_category_id', null);

        // Un ingreso no se clasifica ni rompe.
        $this->post('/fondos/movimiento', ['cuenta_fondos_id' => $this->caja->id, 'fecha' => '2026-11-03', 'tipo' => 'ingreso', 'monto' => 500, 'concepto' => 'Edenor devolución'])->assertSessionHasNoErrors();
        $this->assertNull(MovimientoFondos::where('concepto', 'Edenor devolución')->first()->expense_category_id);
    }

    public function test_extracto_del_banco_trae_la_categoria_sugerida_y_la_ia_clasifica_lo_que_queda_una_sola_vez(): void
    {
        $this->empresa->forceFill(['ia_settings' => ['api_key' => 'sk-test', 'modelo' => 'claude-haiku-5-5']])->save();
        $csv = "Fecha;Concepto;Importe;Saldo\n01/11/2026;Deposito inicial;100000,00;100000,00\n02/11/2026;DEB AUT NATURGY 4471;-6000,00;94000,00\n03/11/2026;PAGO FERRETERIA EL TORNILLO;-4000,00;90000,00\n04/11/2026;PAGO FERRETERIA EL TORNILLO;-1500,00;88500,00\n";
        $this->post('/contable/conciliacion/importar', ['cuenta_fondos_id' => $this->banco->id, 'archivo' => UploadedFile::fake()->createWithContent('b.csv', $csv)])->assertSessionHasNoErrors();
        $c = app(BancoCajonesService::class)->cajones($this->banco->fresh());
        $items = collect($c['solo_banco']['grupos'])->pluck('items')->flatten(1)->keyBy('descripcion');
        $this->assertSame('Gas', $items['DEB AUT NATURGY 4471']['categoria']['nombre']);
        $this->assertNull($items['PAGO FERRETERIA EL TORNILLO']['categoria']);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"items":[{"i":0,"id":null,"nueva":"Herramientas","confianza":0.85}]}']]])]);
        $this->post('/contable/conciliacion/clasificar-ia', ['cuenta' => $this->banco->id])->assertSessionHas('success', fn($m) => str_contains($m, '2 de 2'));
        Http::assertSentCount(1);
        $pedido = Http::recorded()[0][0]->data();
        $this->assertStringContainsString('FERRETERIA EL TORNILLO', $pedido['messages'][0]['content']);
        $this->assertStringContainsString('Gas', $pedido['system'], 'La IA ve las categorías de la empresa');
        $herr = ExpenseCategory::where('name', 'Herramientas')->first(); $this->assertNotNull($herr);

        // Ya quedó aprendido: la segunda vez no hay IA, y los dos renglones de la ferretería traen la categoría.
        $c = app(BancoCajonesService::class)->cajones($this->banco->fresh());
        $items = collect($c['solo_banco']['grupos'])->pluck('items')->flatten(1)->where('descripcion', 'PAGO FERRETERIA EL TORNILLO');
        $this->assertCount(2, $items); foreach ($items as $it) { $this->assertSame($herr->id, $it['categoria']['id']); $this->assertSame('aprendido', $it['categoria']['origen']); }
        $this->post('/contable/conciliacion/clasificar-ia', ['cuenta' => $this->banco->id])->assertSessionHas('success', fn($m) => str_contains($m, 'ya tienen categoría'));
        Http::assertSentCount(1);

        // Registrar desde el extracto sin elegir categoría: toma la sugerida.
        $it = \App\Models\ExtractoItem::where('descripcion', 'DEB AUT NATURGY 4471')->first();
        $this->post('/contable/conciliacion/' . $it->id . '/registrar')->assertSessionHasNoErrors();
        $this->assertSame('Gas', MovimientoFondos::where('egreso', 6000)->first()->categoria->name);
        $this->assertAsientosBalancean();
    }

    public function test_bigcito_registra_un_gasto_y_la_categoria_cae_sola(): void
    {
        $r = app(Catalogo::class)->llamar($this->dueno, 'registrar_gasto', ['concepto' => 'Recarga celular Personal', 'monto' => 3000], 'asistente');
        $this->assertStringContainsString('categoría Internet y teléfono', $r['detalle']);
        app(Catalogo::class)->llamar($this->dueno->fresh(), 'confirmar_accion', ['propuesta_id' => $r['propuesta_id']], 'asistente');
        $this->assertSame('Internet y teléfono', MovimientoFondos::where('concepto', 'Recarga celular Personal')->first()->categoria->name);
    }
}
