<?php

namespace Tests\Feature;

use App\Mcp\Catalogo;
use App\Services\Integraciones\CrmSaludService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Ajustes post Fase 75: la salud del ERP también muestra lo que ve el CRM (GET /api/v1/erp/salud) y se pone amarilla si del otro lado hay caídos.
class CrmSaludDosMiradasTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => 'wh', 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
    }

    public function test_lo_que_ve_el_crm_se_muestra_y_pinta_amarillo_si_del_otro_lado_hay_caidos(): void
    {
        $crm = ['estado' => 'verde', 'texto' => 'Todo en orden: el ERP responde en 80 ms, 12 eventos OK en las últimas 24 horas.', 'ping' => ['ok' => true], 'cola' => ['pendientes' => 0, 'fallidos' => 0], 'errores_24h' => 0, 'ok_24h' => 12, 'ultimo_enviado' => 'quote.accepted', 'ultimo_recibido' => 'comprobante.emitido', 'ultimos' => []];
        Http::fake(['crm.bigsysweb.test/api/v1/erp/salud' => function () use (&$crm) { return Http::response(['data' => $crm]); }, 'crm.bigsysweb.test/api/v1/me' => Http::response(['data' => ['company' => ['name' => 'CRM']]]), 'crm.bigsysweb.test/*' => Http::response(['data' => []])]);
        $r = app(CrmSaludService::class)->resumen($this->empresa->fresh(), true);
        $this->assertSame('verde', $r['estado']); $this->assertSame('verde', $r['crm']['estado']); $this->assertSame(12, $r['crm']['ok_24h']); $this->assertSame('quote.accepted', $r['crm']['ultimo_enviado']);
        $props = $this->get('/configuracion/crm')->assertOk()->viewData('page')['props'];
        $this->assertSame('verde', $props['salud']['crm']['estado']);

        // El CRM tiene avisos hacia el ERP caídos: acá se ve amarillo con su texto.
        $crm = ['estado' => 'rojo', 'texto' => 'Hay 3 aviso(s) al ERP que agotaron los reintentos.', 'cola' => ['pendientes' => 1, 'fallidos' => 3], 'errores_24h' => 3, 'ok_24h' => 5];
        Cache::flush();
        $r = app(CrmSaludService::class)->resumen($this->empresa->fresh(), true);
        $this->assertSame('amarillo', $r['estado']); $this->assertStringContainsString('Del lado del CRM: Hay 3 aviso(s)', $r['texto']); $this->assertSame(3, $r['crm']['cola']['fallidos']);
        // Con la caché todavía vigente, la API con ?ping=1 lo refresca en el acto.
        $crm['texto'] = 'Todo en orden otra vez.'; $crm['estado'] = 'verde'; $crm['cola'] = ['pendientes' => 0, 'fallidos' => 0];
        $token = $this->dueno->createToken('api')->plainTextToken;
        $this->assertSame('rojo', $this->withToken($token)->getJson('/api/crm/salud')->assertOk()->json('data.crm.estado'), 'Sin ping sirve la caché');
        $this->assertSame('verde', $this->withToken($token)->getJson('/api/crm/salud?ping=1')->assertOk()->json('data.crm.estado'), 'Con ping=1 va a buscarlo');
        $crm['estado'] = 'rojo'; $crm['texto'] = 'Hay 3 aviso(s) al ERP que agotaron los reintentos.'; $crm['cola'] = ['pendientes' => 1, 'fallidos' => 3]; Cache::flush();
        $this->actingAs($this->dueno);
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'estado_crm', [], 'asistente');
        $this->assertSame('rojo', $m['lo_que_ve_el_crm']['estado']); $this->assertSame(3, $m['lo_que_ve_el_crm']['caidos']);
    }

    public function test_si_el_crm_no_tiene_el_endpoint_no_pasa_nada(): void
    {
        Http::fake(['crm.bigsysweb.test/api/v1/erp/salud' => Http::response('no', 404), 'crm.bigsysweb.test/api/v1/me' => Http::response(['data' => ['company' => ['name' => 'CRM']]]), 'crm.bigsysweb.test/*' => Http::response(['data' => []])]);
        $r = app(CrmSaludService::class)->resumen($this->empresa->fresh(), true);
        $this->assertSame('verde', $r['estado']); $this->assertNull($r['crm']);
    }
}
