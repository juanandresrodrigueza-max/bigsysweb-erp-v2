<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Services\Fiscal\BcraService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 120.2: consulta a la Central de Deudores del BCRA antes de fiar o aceptar un cheque.
class Fase120bTest extends ErpTestCase
{
    private const BASE = 'api.bcra.gob.ar/centraldedeudores/v1.0';

    private array $rutas = []; private ?string $falla = null; private bool $armado = false;

    // Un solo simulador del BCRA por test (los fakes de Http se apilan y gana el primero): se le cambia qué contesta.
    private function bcra(array $porRuta, ?string $falla = null): void
    {
        Cache::flush(); $this->rutas = $porRuta; $this->falla = $falla;
        if ($this->armado) return; $this->armado = true;
        Http::fake([self::BASE . '/*' => function ($req) {
            if ($this->falla === 'caido') return Http::response('caído', 503);
            if ($this->falla === 'ssl') throw new \Illuminate\Http\Client\ConnectionException('cURL error 60: SSL certificate problem');
            foreach ($this->rutas as $parte => $resp) if (str_contains($req->url(), $parte)) return is_int($resp) ? Http::response(['status' => $resp, 'errorMessages' => ['No se encontró datos']], $resp) : Http::response(['status' => 200, 'results' => $resp]);
            return Http::response(['status' => 404], 404);
        }]);
    }

    private function malo(): array
    {
        return [
            '/Deudas/Historicas/' => ['identificacion' => 30712345678, 'denominacion' => 'LOPEZ SRL', 'periodos' => [['periodo' => '202505', 'entidades' => [['entidad' => 'BANCO GALICIA', 'situacion' => 4, 'monto' => 900.0]]]]],
            '/Deudas/ChequesRechazados/' => ['identificacion' => 30712345678, 'denominacion' => 'LOPEZ SRL', 'causales' => [['causal' => 'SIN FONDOS', 'entidades' => [['entidad' => 7, 'detalle' => [
                ['nroCheque' => 111, 'fechaRechazo' => '2026-09-10', 'monto' => 150000.0, 'fechaPago' => null], ['nroCheque' => 112, 'fechaRechazo' => '2026-06-01', 'monto' => 50000.0, 'fechaPago' => '2026-06-20']]]]]]],
            '/Deudas/' => ['identificacion' => 30712345678, 'denominacion' => 'LOPEZ SRL', 'periodos' => [
                ['periodo' => '202609', 'entidades' => [['entidad' => 'BANCO GALICIA', 'situacion' => 1, 'monto' => 1.0]]],
                ['periodo' => '202610', 'entidades' => [['entidad' => 'BANCO DE LA NACION ARGENTINA', 'situacion' => 1, 'monto' => 1500.0, 'diasAtrasoPago' => 0], ['entidad' => 'BANCO GALICIA', 'situacion' => 3, 'monto' => 820.5, 'diasAtrasoPago' => 95, 'refinanciaciones' => true, 'procesoJud' => false]]]]],
        ];
    }

    public function test_arma_el_resumen_el_semaforo_y_pasa_los_montos_a_pesos(): void
    {
        $this->bcra($this->malo());
        $r = app(BcraService::class)->consultar('30-71234567-8');
        $this->assertTrue($r['ok']); $this->assertSame('30712345678', $r['cuit']); $this->assertSame('LOPEZ SRL', $r['denominacion']); $this->assertSame('10/2026', $r['periodo'], 'toma el último período');
        $this->assertSame(3, $r['peor']); $this->assertSame(4, $r['peor_24m']); $this->assertEqualsWithDelta(2320500, $r['total'], 0.01, 'el BCRA informa en miles de pesos');
        $this->assertSame('BANCO GALICIA', $r['entidades'][0]['entidad'], 'la peor primero'); $this->assertSame(95, $r['entidades'][0]['dias_atraso']); $this->assertTrue($r['entidades'][0]['refinanciado']);
        $this->assertSame(2, $r['cheques']['cantidad']); $this->assertSame(1, $r['cheques']['sin_pagar']); $this->assertEqualsWithDelta(150000, $r['cheques']['monto_sin_pagar'], 0.01); $this->assertSame('2026-09-10', $r['cheques']['ultimo']);
        $this->assertSame('rojo', $r['semaforo']);
        $this->assertStringContainsString('Peor situación: 3', $r['resumen']); $this->assertStringContainsString('2 entidades', $r['resumen']); $this->assertStringContainsString('llegó a situación 4', $r['resumen']); $this->assertStringContainsString('1 sin pagar', $r['resumen']);
        // La segunda consulta sale de la memoria: no vuelve a pegarle al BCRA.
        $n = count(Http::recorded()); $r2 = app(BcraService::class)->consultar('30712345678');
        $this->assertTrue($r2['de_cache']); $this->assertCount($n, Http::recorded());
    }

    public function test_quien_no_figura_queda_en_verde_y_los_errores_se_explican(): void
    {
        $this->bcra([]); // todo 404: no figura
        $r = app(BcraService::class)->consultar('20-12345678-9');
        $this->assertTrue($r['ok']); $this->assertSame('verde', $r['semaforo']); $this->assertSame(0, $r['peor']); $this->assertStringContainsString('No figura', $r['resumen']);
        $this->assertFalse(app(BcraService::class)->consultar('123')['ok']);

        // Sólo con seguimiento especial o un cheque ya pagado: amarillo.
        $this->bcra(['/Deudas/Historicas/' => 404, '/Deudas/ChequesRechazados/' => 404, '/Deudas/' => ['denominacion' => 'X', 'periodos' => [['periodo' => '202610', 'entidades' => [['entidad' => 'BANCO', 'situacion' => 2, 'monto' => 10]]]]]]);
        $this->assertSame('amarillo', app(BcraService::class)->consultar('20123456789')['semaforo']);

        $this->bcra([], 'caido');
        $r = app(BcraService::class)->consultar('20123456789'); $this->assertFalse($r['ok']); $this->assertStringContainsString('respondió con un error', $r['error']);
        $this->bcra([], 'ssl');
        $this->assertStringContainsString('certificado', app(BcraService::class)->consultar('20123456789')['error']);
    }

    public function test_desde_la_ficha_del_cliente_consulta_y_queda_guardado(): void
    {
        $this->bcra($this->malo());
        $c = $this->cliente(['name' => 'López SRL', 'cuit' => '30-71234567-8']); $sin = $this->cliente(['name' => 'Sin Cuit', 'cuit' => null]);
        $this->get("/clientes/{$c->id}")->assertInertia(fn($p) => $p->where('bcra', null));
        $this->post("/clientes/{$c->id}/bcra")->assertRedirect()->assertSessionHas('success', fn($m) => str_contains($m, 'Peor situación: 3'));
        $c->refresh(); $this->assertSame('rojo', $c->bcra['semaforo']); $this->assertNotNull($c->bcra_en);
        $this->get("/clientes/{$c->id}")->assertInertia(fn($p) => $p->where('bcra.semaforo', 'rojo')->where('bcra.peor', 3)->where('bcra.viejo', false)->has('bcra.entidades', 2));
        $this->post("/clientes/{$sin->id}/bcra")->assertSessionHasErrors('bcra');
        // Bigcito, con la ficha abierta, ya lo sabe.
        $v = app(\App\Services\IA\PantallaService::class)->contexto($this->dueno, "/clientes/{$c->id}");
        $this->assertStringContainsString('BCRA (', $v['texto']); $this->assertStringContainsString('Peor situación: 3', $v['texto']);
    }

    public function test_bigcito_consulta_por_cliente_o_por_un_cuit_suelto(): void
    {
        $this->bcra($this->malo()); $cat = app(Catalogo::class);
        $c = $this->cliente(['name' => 'López SRL', 'cuit' => '30-71234567-8', 'credit_limit' => 500000]);
        $r = $cat->llamar($this->dueno, 'situacion_bcra', ['cliente' => 'lopez'], 'asistente');
        $this->assertSame('López SRL', $r['quien']); $this->assertSame('rojo', $r['semaforo']); $this->assertSame(3, $r['peor_situacion']); $this->assertEqualsWithDelta(500000, $r['limite_de_credito_actual'], 0.01); $this->assertStringContainsString('garantía', $r['consejo']);
        $this->assertSame('rojo', $c->fresh()->bcra['semaforo'], 'queda en la ficha');
        // El firmante de un cheque, que no es cliente.
        $r = $cat->llamar($this->dueno, 'situacion_bcra', ['cuit' => '30712345678'], 'asistente');
        $this->assertSame('LOPEZ SRL', $r['quien']); $this->assertNull($r['limite_de_credito_actual']);
        $this->assertSame('rojo', $cat->llamar($this->dueno, 'situacion_bcra', ['cliente' => '30-71234567-8'], 'asistente')['semaforo'], 'un CUIT escrito como cliente también sirve');
        $sin = $this->cliente(['name' => 'Gómez', 'cuit' => null]);
        $this->assertStringContainsString('no tiene el CUIT', $cat->llamar($this->dueno, 'situacion_bcra', ['cliente' => 'Gómez'], 'asistente')['error']);
        $this->assertArrayHasKey('error', $cat->llamar($this->dueno, 'situacion_bcra', [], 'asistente'));
        $this->assertStringContainsString('BCRA (', $cat->llamar($this->dueno, 'ficha_cliente', ['cliente' => 'López SRL'], 'asistente')['banco_central']);
    }
}
