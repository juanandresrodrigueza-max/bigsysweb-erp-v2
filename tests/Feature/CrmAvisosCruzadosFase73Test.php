<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Cheque;
use App\Services\Fondos\ChequeService;
use App\Services\Integraciones\CrmAvisosService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 73 (lado ERP): lo crítico del ERP al vendedor en el CRM; las tareas vencidas del CRM en la campana del ERP.
class CrmAvisosCruzadosFase73Test extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
    }

    private array $tareasCrm = [];

    private function fakeCrm(array $tareas = []): void
    {
        $this->tareasCrm = $tareas;
        Http::fake([
            'crm.bigsysweb.test/api/v1/tasks?*' => fn() => Http::response(['data' => $this->tareasCrm]),
            'crm.bigsysweb.test/api/v1/tasks' => Http::sequence()->push(['data' => ['id' => 9001]], 201)->push(['data' => ['id' => 9002]], 201)->push(['data' => ['id' => 9003]], 201)->push(['data' => ['id' => 9004]], 201),
            'crm.bigsysweb.test/*' => Http::response(['data' => []]),
        ]);
    }

    public function test_la_deuda_vieja_y_el_limite_pasado_le_llegan_al_vendedor_como_tarea_del_crm_una_sola_vez(): void
    {
        $this->fakeCrm();
        $art = $this->articulo(['price' => 1000]);
        $viejo = $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        $this->factura($viejo, [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['fecha' => today()->subDays(60)->toDateString()])->update(['fecha_vto' => today()->subDays(45)->toDateString()]);
        $pasado = $this->cliente(['name' => 'Gómez Hnos', 'crm_external_id' => '778']);
        $this->factura($pasado, [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]]);
        $pasado->update(['credit_limit' => 5000]); // le bajaron el límite después de facturar
        $sinCrm = $this->cliente(['name' => 'Sin CRM']);
        $this->factura($sinCrm, [['product_id' => $art->id, 'cantidad' => 10, 'precio_unit' => 1000]], ['fecha' => today()->subDays(60)->toDateString()])->update(['fecha_vto' => today()->subDays(45)->toDateString()]);
        $alDia = $this->cliente(['name' => 'Al Día SA', 'crm_external_id' => '779']);
        $this->factura($alDia, [['product_id' => $art->id, 'cantidad' => 1, 'precio_unit' => 1000]]);

        $r = app(CrmAvisosService::class)->revisar($this->empresa->fresh());
        $this->assertSame(2, $r['al_crm']);
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && str_starts_with($q['title'], 'Deuda vencida hace 45 días: López SRL') && $q['contact_id'] === 777 && $q['priority'] === 'high' && str_contains($q['description'], 'Aviso automático del ERP'));
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && str_starts_with($q['title'], 'Pasó su límite de crédito: Gómez Hnos') && $q['contact_id'] === 778);
        Http::assertNotSent(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && str_contains($q['title'], 'Sin CRM'));

        // Al otro día no se repite el mismo aviso.
        Artisan::call('crm:avisos');
        $this->assertSame(2, Http::recorded(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && $q->method() === 'POST')->count(), 'Un aviso por cliente y motivo cada 30 días');
        $this->assertTrue(\App\Models\CrmEvento::withoutGlobalScopes()->where('evento', 'aviso.deuda_vieja')->where('referencia', "cliente:{$viejo->id}")->where('estado', 'ok')->exists());
    }

    public function test_un_cheque_rechazado_avisa_al_vendedor_al_instante(): void
    {
        $this->fakeCrm();
        $cli = $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        $ch = Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => '4455', 'banco' => 'Galicia', 'emisor' => 'López SRL', 'fecha_emision' => today()->subDays(10)->toDateString(), 'fecha_pago' => today()->toDateString(), 'monto' => 25000, 'estado' => 'cartera', 'contact_id' => $cli->id]);
        app(ChequeService::class)->rechazar($ch, 'sin fondos');
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && $q['title'] === 'Cheque rechazado: López SRL' && str_contains($q['description'], '4455') && str_contains($q['description'], 'sin fondos') && $q['contact_id'] === 777);

        // Sin CRM conectado no pasa nada (y no rompe el rechazo).
        $this->empresa->update(['crm_settings' => ['activo' => false]]);
        $ch2 = Cheque::create(['business_id' => $this->empresa->id, 'tipo' => 'tercero', 'numero' => '4456', 'banco' => 'Galicia', 'emisor' => 'López SRL', 'fecha_emision' => today()->toDateString(), 'fecha_pago' => today()->toDateString(), 'monto' => 100, 'estado' => 'cartera', 'contact_id' => $cli->id]);
        app(ChequeService::class)->rechazar($ch2->fresh(), 'firma');
        $this->assertSame('rechazado', $ch2->fresh()->estado);
        $this->assertSame(1, Http::recorded(fn($q) => str_ends_with($q->url(), '/api/v1/tasks') && $q->method() === 'POST')->count());
    }

    public function test_las_tareas_vencidas_del_crm_aparecen_en_la_campana_y_se_resuelven_cuando_las_cierran(): void
    {
        $this->fakeCrm([
            ['id' => 501, 'title' => 'Llamar por el presupuesto', 'status' => 'pending', 'priority' => 'high', 'contact_id' => 777, 'due_date' => today()->subDays(2)->toDateString(), 'assigned_to_name' => 'Juan Vendedor'],
            ['id' => 502, 'title' => 'Mandar catálogo', 'status' => 'pending', 'priority' => 'low', 'contact_id' => 888, 'due_date' => today()->toDateString(), 'assigned_to_name' => 'Ana'],
        ]);
        $cli = $this->cliente(['name' => 'López SRL', 'crm_external_id' => '777']);
        Artisan::call('crm:avisos');
        $a = Alerta::withoutGlobalScopes()->where('tipo', 'crm_tarea')->whereNull('resuelta_en')->get();
        $this->assertCount(1, $a, 'Sólo la vencida; la de hoy todavía no');
        $this->assertSame('critica', $a[0]->severidad); $this->assertSame(501, (int) $a[0]->modelo_id); $this->assertStringContainsString('Llamar por el presupuesto', $a[0]->titulo);
        $this->assertStringContainsString('López SRL', $a[0]->detalle); $this->assertStringContainsString('Juan Vendedor', $a[0]->detalle); $this->assertStringContainsString(urlencode('/tasks?id=501'), $a[0]->url);
        // En la campana del dueño.
        $this->assertTrue(Alerta::visiblesPara($this->dueno->fresh())->noLeidasPor($this->dueno->id)->where('tipo', 'crm_tarea')->exists());

        // El vendedor la cierra en el CRM → webhook task.completed → se resuelve al instante.
        $cuerpo = json_encode(['event' => 'task.completed', 'data' => ['task' => ['id' => 501, 'title' => 'Llamar por el presupuesto']]]);
        auth()->logout();
        $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $cuerpo, self::WH), 'CONTENT_TYPE' => 'application/json'], $cuerpo)->assertOk();
        $this->assertNotNull(Alerta::withoutGlobalScopes()->where('tipo', 'crm_tarea')->where('modelo_id', 501)->first()->resuelta_en);

        // Una que recién vence llega por task.overdue sin esperar la pasada diaria.
        $cuerpo = json_encode(['event' => 'task.overdue', 'data' => ['task' => ['id' => 503, 'title' => 'Visitar la obra', 'priority' => 'medium', 'due_date' => today()->subDay()->toDateString(), 'contact_id' => 777, 'assigned_to_name' => 'Juan Vendedor']]]);
        $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $cuerpo, self::WH), 'CONTENT_TYPE' => 'application/json'], $cuerpo)->assertOk()->assertJsonPath('ok', true);
        $n = Alerta::withoutGlobalScopes()->where('tipo', 'crm_tarea')->where('modelo_id', 503)->whereNull('resuelta_en')->first();
        $this->assertSame('aviso', $n->severidad); $this->assertStringContainsString('López SRL', $n->detalle);

        // En la pasada siguiente el CRM ya no la lista como pendiente → se resuelve.
        Cache::flush(); $this->tareasCrm = [];
        Artisan::call('crm:avisos');
        $this->assertSame(0, Alerta::withoutGlobalScopes()->where('tipo', 'crm_tarea')->whereNull('resuelta_en')->count());
    }
}
