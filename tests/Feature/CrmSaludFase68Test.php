<?php

namespace Tests\Feature;

use App\Jobs\NotificarCrmJob;
use App\Mcp\Catalogo;
use App\Models\Alerta;
use App\Models\CrmEvento;
use App\Services\Integraciones\CrmSaludService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 68: salud de la conexión ERP ↔ CRM: bitácora en los dos sentidos, semáforo, alerta cuando se corta, reintentos y reconciliación.
class CrmSaludFase68Test extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
    }

    private function crmOk(): void
    {
        Http::fake(['crm.bigsysweb.test/api/v1/me' => Http::response(['company' => ['name' => 'Ferretería López CRM']]), 'crm.bigsysweb.test/api/erp/webhook' => Http::response(['ok' => true]),
            'crm.bigsysweb.test/api/v1/contacts/sync' => Http::response(['data' => ['summary' => ['total' => 1], 'results' => []]]), 'crm.bigsysweb.test/api/v1/products/sync' => Http::response(['data' => ['summary' => ['total' => 1], 'results' => []]])]);
    }

    public function test_cada_evento_queda_en_la_bitacora_y_el_semaforo_esta_verde(): void
    {
        $this->crmOk();
        $cli = $this->cliente(['name' => 'López SRL']); $p = $this->articulo(['price' => 1000, 'stock_inicial' => 10]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);
        $ev = CrmEvento::where('business_id', $this->empresa->id)->get();
        $this->assertTrue($ev->contains(fn($e) => $e->direccion === 'salida' && $e->evento === 'sync.contactos' && $e->estado === 'ok'), 'El cliente viajó');
        $fac = $ev->first(fn($e) => $e->evento === 'comprobante.emitido'); $this->assertNotNull($fac); $this->assertSame('ok', $fac->estado); $this->assertSame(200, $fac->http); $this->assertSame($f->numeroFormateado(), $fac->referencia);

        // Lo que entra desde el CRM también queda: firma válida → ok; firma inválida → rechazado.
        $cuerpo = json_encode(['event' => 'contact.created', 'data' => ['contact' => ['id' => 501, 'name' => 'Nuevo del CRM', 'phone' => '3510000000']]]);
        $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $cuerpo, self::WH), 'CONTENT_TYPE' => 'application/json'], $cuerpo)->assertOk();
        $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=malo', 'HTTP_X_BIGSYSWEB_EVENT' => 'contact.created', 'CONTENT_TYPE' => 'application/json'], $cuerpo . ' ')->assertStatus(401);
        $ent = CrmEvento::where('direccion', 'entrada')->orderBy('id')->get();
        $this->assertSame(['ok', 'rechazado'], $ent->pluck('estado')->all()); $this->assertSame('crm #501', $ent[0]->referencia); $this->assertStringContainsString('secreto del webhook', $ent[1]->detalle);

        $r = app(CrmSaludService::class)->resumen($this->empresa->fresh());
        $this->assertSame('amarillo', $r['estado'], 'Un rechazo en 24 h pinta amarillo'); $this->assertSame(1, $r['errores_24h']); $this->assertSame('comprobante.emitido', $r['ultimo_enviado']['evento']); $this->assertSame('contact.created', $r['ultimo_recibido']['evento']);
        $this->assertTrue($r['ping']['ok']); $this->assertStringContainsString('Ferretería López CRM', $r['ping']['detalle']);

        $props = $this->get('/configuracion/crm')->assertOk()->viewData('page')['props'];
        $this->assertSame('amarillo', $props['salud']['estado']); $this->assertNotEmpty($props['salud']['ultimos']);
        $m = app(Catalogo::class)->llamar($this->dueno->fresh(), 'estado_crm', [], 'asistente');
        $this->assertSame('amarillo', $m['estado']); $this->assertSame(0, $m['fallidos']);
    }

    public function test_si_el_crm_no_responde_queda_el_error_salta_la_alerta_y_se_resuelve_cuando_vuelve(): void
    {
        $caido = true; Http::fake(['crm.bigsysweb.test/*' => function () use (&$caido) { return $caido ? Http::response('caído', 503) : Http::response(['company' => ['name' => 'CRM'], 'ok' => true]); }]); // un segundo Http::fake no pisa al primero: se cambia la variable
        try { (new NotificarCrmJob($this->empresa->id, 'comprobante.emitido', ['id' => 1, 'numero' => 'A 0001-00000001']))->handle(); $this->fail('Tenía que fallar para que la cola reintente'); } catch (\RuntimeException $e) {}
        $e = CrmEvento::latest('id')->first(); $this->assertSame('error', $e->estado); $this->assertSame(503, $e->http); $this->assertSame('A 0001-00000001', $e->referencia);

        $svc = app(CrmSaludService::class); $r = $svc->resumen($this->empresa->fresh(), true);
        $this->assertSame('rojo', $r['estado']); $this->assertStringContainsString('no responde', $r['texto']);
        $svc->alertar($this->empresa, $r);
        $al = Alerta::withoutGlobalScopes()->where('tipo', 'crm_conexion')->whereNull('resuelta_en')->first(); $this->assertNotNull($al); $this->assertSame('critica', $al->severidad); $this->assertSame('/configuracion/crm', $al->url);

        // Vuelve el CRM: el comando lo ve y resuelve la alerta.
        $caido = false;
        Artisan::call('crm:salud');
        $this->assertNotNull($al->fresh()->resuelta_en, 'La alerta se resolvió sola');
        $this->assertTrue(CrmEvento::where('evento', 'conexion')->where('detalle', 'like', '%volvió%')->exists());
    }

    public function test_la_reconciliacion_vuelve_a_mandar_lo_de_las_ultimas_48_horas(): void
    {
        $this->crmOk();
        $cli = $this->cliente(['name' => 'López SRL']); $p = $this->articulo(['price' => 1000, 'stock_inicial' => 10]);
        $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000, 'alicuota_iva' => 21]]);
        $antes = CrmEvento::count(); Http::recorded(); // limpia la cuenta de arriba
        $this->post('/configuracion/crm/reconciliar')->assertSessionHas('success', fn($m) => str_contains($m, '1 comprobantes'));
        $rec = CrmEvento::where('evento', 'reconciliacion')->first(); $this->assertNotNull($rec); $this->assertMatchesRegularExpression('/^[12] clientes/', $rec->referencia, 'López (y el consumidor final del setup)'); $this->assertStringContainsString('1 comprobantes', $rec->referencia);
        $this->assertGreaterThan($antes + 1, CrmEvento::count(), 'Los reenvíos también quedan en la bitácora');
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/erp/webhook') && ($r['datos']['reconciliacion'] ?? false) === true);
        $this->assertSame(0, app(CrmSaludService::class)->reintentar($this->empresa), 'Sin fallidos no hay nada que reintentar');
    }
}
