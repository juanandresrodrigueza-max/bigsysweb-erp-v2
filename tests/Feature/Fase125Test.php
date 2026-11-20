<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Canal;
use App\Models\Contact;
use App\Models\SistemaConfig;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 125: los avisos de datos personales (LGPD) que Tienda Nube exige a las aplicaciones.
class Fase125Test extends ErpTestCase
{
    private Canal $canal;

    protected function setUp(): void
    {
        parent::setUp();
        SistemaConfig::set('tn_app_secret', 'secreto-de-la-app');
        Http::fake(['*' => Http::response([], 200)]);
        $this->canal = Canal::withoutGlobalScopes()->create(['business_id' => $this->empresa->id, 'tipo' => 'tiendanube', 'nombre' => 'Tienda Nube', 'activo' => true,
            'external_user_id' => '777', 'credenciales' => ['access_token' => 'tok', 'store_id' => '777', 'secret' => 'secreto-de-la-app'], 'metodo' => 'oauth']);
    }

    private function aviso(string $tema, array $cuerpo, ?string $secreto = 'secreto-de-la-app')
    {
        $json = json_encode($cuerpo);
        $h = $secreto ? ['X-Linkedstore-Hmac-Sha256' => hash_hmac('sha256', $json, $secreto)] : [];
        return $this->call('POST', "/api/tiendanube/lgpd/{$tema}", [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], collect($h)->mapWithKeys(fn($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all()), $json);
    }

    public function test_sin_firma_o_con_otra_firma_se_rechaza(): void
    {
        $this->aviso('store-redact', ['store_id' => 777], null)->assertStatus(401);
        $this->aviso('store-redact', ['store_id' => 777], 'otro')->assertStatus(401);
        $this->assertNotNull($this->canal->fresh()->credenciales);
        $this->post('/api/tiendanube/lgpd/cualquier-cosa')->assertNotFound();
    }

    public function test_borrar_la_tienda_borra_sus_claves_y_avisa(): void
    {
        $this->aviso('store-redact', ['store_id' => 777])->assertOk()->assertJson(['ok' => true]);
        $c = $this->canal->fresh();
        $this->assertNull($c->credenciales); $this->assertFalse((bool) $c->activo); $this->assertNull($c->external_user_id);
        $a = Alerta::withoutGlobalScopes()->where('business_id', $this->empresa->id)->where('tipo', 'tiendanube_lgpd')->firstOrFail();
        $this->assertStringContainsString('se borraron las claves', $a->titulo);
        // Una tienda que no está en el ERP: se contesta bien y no pasa nada.
        $this->aviso('store-redact', ['store_id' => 999])->assertOk()->assertJson(['tienda' => 'desconocida']);
    }

    public function test_borrar_un_comprador_le_avisa_a_la_empresa_sin_borrar_nada(): void
    {
        $cli = Contact::withoutGlobalScopes()->create(['business_id' => $this->empresa->id, 'type' => 'customer', 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'phone' => '1155551234']);
        $this->aviso('customers-redact', ['store_id' => 777, 'customer' => ['id' => 5, 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'identification' => '30111222'], 'orders_to_redact' => [101, 102]])->assertOk();
        $a = Alerta::withoutGlobalScopes()->where('business_id', $this->empresa->id)->where('tipo', 'tiendanube_lgpd')->firstOrFail();
        $this->assertStringContainsString('pidió que borres sus datos', $a->titulo);
        $this->assertStringContainsString('Ana Pérez', $a->detalle); $this->assertStringContainsString('101, 102', $a->detalle); $this->assertStringContainsString('10 años', $a->detalle);
        $this->assertSame("/clientes/{$cli->id}", $a->url);
        $this->assertSame('1155551234', $cli->fresh()->phone); // lo resuelve la empresa
        $this->assertNotNull($this->canal->fresh()->credenciales);
    }

    public function test_pedido_de_copia_avisa_aunque_el_comprador_no_este_en_el_erp(): void
    {
        $this->aviso('customers-data-request', ['store_id' => 777, 'customer' => ['id' => 9, 'email' => 'nadie@example.com'], 'orders_requested' => [300], 'data_request' => ['id' => 42]])->assertOk();
        $a = Alerta::withoutGlobalScopes()->where('business_id', $this->empresa->id)->where('tipo', 'tiendanube_lgpd')->firstOrFail();
        $this->assertStringContainsString('pidió una copia de sus datos', $a->titulo);
        $this->assertStringContainsString('No hay un cliente del ERP', $a->detalle); $this->assertStringContainsString('n.º 42', $a->detalle);
        $this->assertSame('/clientes', $a->url);
    }

    public function test_el_aviso_viejo_de_tienda_nube_ya_no_se_rompe(): void
    {
        $this->postJson('/api/tiendanube/webhook', ['event' => 'orders/paid'])->assertOk();
    }
}
