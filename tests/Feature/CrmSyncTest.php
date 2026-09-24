<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Integraciones\CrmSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Integración CRM · etapa 2: clientes y artículos sincronizados en los dos sentidos.
class CrmSyncTest extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 2]]);
        $this->dueno->unsetRelation('business');
        Http::fake([
            'crm.bigsysweb.test/api/v1/contacts/sync' => function ($r) { return Http::response(['data' => ['summary' => ['total' => count($r['items']), 'created' => count($r['items']), 'updated' => 0, 'errors' => 0], 'results' => array_map(fn($i) => ['external_id' => $i['external_id'], 'action' => 'created', 'id' => 900 + (int) $i['external_id']], $r['items'])]], 200); },
            'crm.bigsysweb.test/api/v1/products/sync' => Http::response(['data' => ['summary' => ['total' => 1, 'created' => 1, 'updated' => 0, 'errors' => 0], 'results' => []]], 200),
        ]);
    }

    public function test_un_cliente_nuevo_o_editado_viaja_al_crm_con_lo_fiscal_y_guarda_el_id_del_crm(): void
    {
        $cli = $this->cliente(['name' => 'López SRL', 'cuit' => '30-70012345-6', 'mobile' => '3511234567', 'address' => 'Av. Colón 100', 'city' => 'Córdoba', 'credit_limit' => 50000]);
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/v1/contacts/sync') && $r->hasHeader('Authorization', 'Bearer bsk_prueba')
            && $r['items'][0]['external_id'] === (string) $cli->id && $r['items'][0]['name'] === 'López SRL' && $r['items'][0]['cuit'] === '30-70012345-6' && $r['items'][0]['condicion_iva'] === 'Responsable Inscripto'
            && $r['items'][0]['domicilio_fiscal'] === 'Av. Colón 100, Córdoba' && $r['items'][0]['phone'] === '3511234567' && $r['items'][0]['lifecycle_stage'] === 'customer' && $r['items'][0]['credit_limit'] == 50000);
        $this->assertSame((string) (900 + $cli->id), $cli->fresh()->crm_external_id, 'Guarda el id del CRM');
        // Un proveedor no viaja como cliente.
        Http::assertSentCount(1);
        $this->proveedor();
        Http::assertSentCount(1);
        // Dos cambios seguidos del mismo cliente se mandan una sola vez (30 s).
        $cli->update(['phone' => '351999']); $cli->update(['phone' => '351998']);
        Http::assertSentCount(1);
        Cache::flush();
        $cli->update(['phone' => '351997']);
        Http::assertSentCount(2);
    }

    public function test_un_articulo_viaja_con_el_precio_de_la_lista_elegida_rubro_y_stock(): void
    {
        $padre = \App\Models\Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Hierros']);
        $hijo = \App\Models\Rubro::create(['business_id' => $this->empresa->id, 'nombre' => 'Aletado', 'parent_id' => $padre->id]);
        Cache::flush();
        $p = $this->articulo(['name' => 'Hierro 8', 'sku' => 'H8', 'price' => 1000, 'prices' => ['2' => 1200], 'stock_inicial' => 30, 'stock_min' => 5, 'rubro_id' => $hijo->id]);
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/v1/products/sync') && collect($r['items'])->contains(fn($i) => $i['external_id'] === (string) $p->id && $i['name'] === 'Hierro 8' && $i['code'] === 'H8' && $i['price'] === '1200.00' && $i['category'] === 'Hierros' && $i['subcategory'] === 'Aletado' && $i['low_stock_threshold'] === 5));
        // Sincronizar todo desde la pantalla encola clientes y artículos.
        $this->post('/configuracion/crm/sincronizar')->assertSessionHas('success');
    }

    public function test_un_contacto_que_nace_en_el_crm_llega_por_webhook_y_no_pisa_lo_fiscal(): void
    {
        $firmar = fn(array $body) => ['cuerpo' => json_encode($body), 'firma' => 'sha256=' . hash_hmac('sha256', json_encode($body), self::WH)];
        $post = function (array $body, ?string $firma = null) use ($firmar) { $f = $firmar($body); return $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => $firma ?? $f['firma'], 'CONTENT_TYPE' => 'application/json'], $f['cuerpo']); };

        // Firma mala → 401; empresa sin integración → 404.
        $post(['event' => 'contact.created', 'data' => ['contact' => ['id' => 55, 'name' => 'X']]], 'sha256=malo')->assertStatus(401);
        $this->call('POST', '/api/crm/webhook/999', [], [], [], [], '{}')->assertStatus(404);

        // Nace en el CRM (WhatsApp nuevo): aparece como cliente consumidor final con el id del CRM.
        $post(['event' => 'contact.created', 'data' => ['contact' => ['id' => 55, 'name' => 'Marta Pérez', 'phone' => '+5493511111111', 'email' => 'marta@mail.com', 'source' => 'whatsapp']]])->assertOk()->assertJsonPath('accion', 'creado');
        $c = Contact::where('crm_external_id', '55')->first();
        $this->assertNotNull($c); $this->assertSame('customer', $c->type); $this->assertSame('Consumidor Final', $c->condicion_iva); $this->assertSame('+5493511111111', $c->mobile);
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/contacts/sync'), 'No rebota al CRM lo que vino del CRM');

        // Mismo cuerpo repetido (reintento del CRM) → no duplica.
        $post(['event' => 'contact.created', 'data' => ['contact' => ['id' => 55, 'name' => 'Marta Pérez', 'phone' => '+5493511111111', 'email' => 'marta@mail.com', 'source' => 'whatsapp']]])->assertOk()->assertJsonPath('duplicado', true);
        $this->assertSame(1, Contact::where('crm_external_id', '55')->count());

        // En el ERP le cargan el CUIT; el CRM manda una edición con otro CUIT y condición: no se pisa lo fiscal, sí el nombre y el teléfono.
        $c->update(['cuit' => '20-12345678-6', 'condicion_iva' => 'Monotributista']);
        $post(['event' => 'contact.updated', 'data' => ['contact' => ['id' => 55, 'name' => 'Marta Pérez Gómez', 'phone' => '+5493512222222', 'cuit' => '27-00000000-0', 'condicion_iva' => 'Responsable Inscripto']]])->assertOk()->assertJsonPath('accion', 'actualizado');
        $c->refresh();
        $this->assertSame('Marta Pérez Gómez', $c->name); $this->assertSame('+5493512222222', $c->mobile); $this->assertSame('20-12345678-6', $c->cuit); $this->assertSame('Monotributista', $c->condicion_iva);

        // Un contacto del CRM que ya existía en el ERP (mismo CUIT) se enlaza en vez de duplicarse.
        $viejo = $this->cliente(['name' => 'Constructora Sur', 'cuit' => '30-50000000-1', 'email' => null]);
        $post(['event' => 'contact.created', 'data' => ['contact' => ['id' => 77, 'name' => 'Constructora Sur SA', 'cuit' => '30500000001']]])->assertOk()->assertJsonPath('accion', 'actualizado');
        $this->assertSame('77', $viejo->fresh()->crm_external_id);
        $this->assertSame(1, Contact::where('name', 'like', 'Constructora Sur%')->count());
        $post(['event' => 'message.received', 'data' => ['message' => ['id' => 1]]])->assertOk()->assertJsonPath('ignorado', 'message.received');
    }
}
