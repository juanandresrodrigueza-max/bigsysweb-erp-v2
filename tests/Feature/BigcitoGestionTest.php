<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Models\Deposito;
use App\Models\Liquidacion;
use App\Models\OrdenEntrega;
use App\Models\TransferenciaStock;
use App\Services\Servicios\OrdenesService;
use Tests\ErpTestCase;

// Bigcito (y Claude o ChatGPT por MCP) hace lo de las fases 34 a 39 hablándole: consultas al instante y acciones con propuesta y confirmación.
class BigcitoGestionTest extends ErpTestCase
{
    private function mcp(string $h, array $a = []): array { return app(Catalogo::class)->llamar($this->dueno, $h, $a, 'asistente'); }
    private function hacer(string $h, array $a = []): array
    {
        $p = $this->mcp($h, $a); $this->assertArrayNotHasKey('error', $p, $p['error'] ?? '');
        return app(Catalogo::class)->llamar($this->dueno, 'confirmar_accion', ['propuesta_id' => $p['propuesta_id']], 'asistente');
    }

    public function test_estan_todas_las_herramientas_nuevas(): void
    {
        $todas = app(Catalogo::class)->todas();
        foreach (['datos_en_masa', 'deshacer_cambio_masivo', 'pases_entre_sucursales', 'recibir_pase', 'estado_conciliaciones', 'registrar_gastos_banco', 'viajes_reparto', 'pendiente_de_entregar', 'armar_viaje', 'acopios', 'taller_que_sigue', 'dejar_esperando', 'que_vuelvan', 'pago_tecnicos', 'firmas_recibos', 'enviar_recibos', 'estado_mis_empresas', 'precios_en_masa'] as $n)
            $this->assertArrayHasKey($n, $todas, "Bigcito no tiene {$n}");
    }

    public function test_datos_en_masa_y_deshacer_por_voz(): void
    {
        $a = $this->articulo(['name' => 'Lavandina', 'marca' => 'Ayudín']); $b = $this->articulo(['name' => 'Detergente', 'marca' => 'Ayudín']);
        $this->assertArrayHasKey('error', $this->mcp('datos_en_masa', ['dato' => 'ubicacion', 'valor' => 'góndola 3']));
        $r = $this->hacer('datos_en_masa', ['dato' => 'ubicacion', 'valor' => 'góndola 3', 'marca' => 'Ayudín']);
        $this->assertStringContainsString('2 artículos', $r['texto']); $this->assertSame('góndola 3', $a->fresh()->ubicacion);
        $this->hacer('deshacer_cambio_masivo'); $this->assertNull($a->fresh()->ubicacion);
    }

    public function test_pase_reparto_taller_sueldos_y_empresas(): void
    {
        // Pase: llegó todo
        $norte = Deposito::create(['business_id' => $this->empresa->id, 'business_location_id' => $this->sucursal->id, 'nombre' => 'Norte', 'activo' => true]);
        $p = $this->articulo(['name' => 'Tornillo', 'stock_inicial' => 50]);
        $t = app(\App\Services\Stock\PasesService::class)->enviar($this->deposito, $norte, [['product_id' => $p->id, 'cantidad' => 10]]);
        $this->assertCount(1, $this->mcp('pases_entre_sucursales')['pases']);
        $this->hacer('recibir_pase', ['pase' => (string) $t->numero]); $this->assertSame('recibida', $t->fresh()->estado); $this->assertEquals(10, $p->fresh()->stockEn($norte->id));
        // Reparto: armar el viaje de un cliente por nombre
        $cli = $this->cliente(['name' => 'Obra Norte']);
        $this->factura($cli, [['product_id' => $p->id, 'descripcion' => 'Tornillo', 'cantidad' => 5, 'precio_unit' => 100, 'alicuota_iva' => 21]], ['entrega_pendiente' => true]);
        $this->assertSame('Obra Norte', $this->mcp('pendiente_de_entregar')['clientes'][0]['cliente']);
        $r = $this->hacer('armar_viaje', ['clientes' => ['obra norte'], 'chofer' => 'Carlos']);
        $this->assertStringContainsString('/reparto/', $r['texto']); $this->assertSame('Carlos', OrdenEntrega::latest('id')->first()->repartidor);
        $this->assertSame('Carlos', $this->mcp('viajes_reparto')['viajes'][0]['chofer']);
        // Taller
        $ot = app(OrdenesService::class)->crear(['contact_id' => $cli->id, 'equipo' => 'Gol', 'falla' => 'Frenos']);
        $this->assertSame('Diagnosticar', collect($this->mcp('taller_que_sigue')['ordenes'])->firstWhere('numero', $ot->numeroFormateado())['que_sigue']);
        $this->hacer('dejar_esperando', ['orden' => (string) $ot->numero, 'motivo' => 'el repuesto']); $this->assertSame('esperando', $ot->fresh()->estado);
        $this->assertArrayHasKey('tecnicos', $this->mcp('pago_tecnicos', ['periodo' => 'mes']));
        $this->assertArrayHasKey('equipos', $this->mcp('que_vuelvan'));
        // Sueldos: no hay liquidación confirmada
        $this->assertArrayHasKey('error', $this->mcp('enviar_recibos'));
        $this->assertArrayHasKey('faltan', $this->mcp('firmas_recibos'));
        // Empresas y conciliaciones
        $e = $this->mcp('estado_mis_empresas', ['mes' => today()->format('Y-m')]); $this->assertSame('Empresa Test', $e['empresas'][0]['empresa']);
        $this->assertArrayHasKey('cupones_en_cartera', $this->mcp('estado_conciliaciones'));
        $this->assertArrayHasKey('totales', $this->mcp('acopios'));
    }

    public function test_un_vendedor_no_puede_lo_que_no_tiene(): void
    {
        $v = $this->usuarioConRol('vendedor', 'v@test.com');
        $r = app(Catalogo::class)->llamar($v, 'datos_en_masa', ['dato' => 'iva', 'valor' => '21', 'texto' => 'a'], 'asistente');
        $this->assertStringContainsString('no tiene permiso', $r['error']);
    }
}
