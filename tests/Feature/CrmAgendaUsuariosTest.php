<?php

namespace Tests\Feature;

use App\Models\Envio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Integración CRM · etapa 4: usuarios espejados, agenda del CRM en el ERP y cobranzas por el CRM.
class CrmAgendaUsuariosTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba']]);
        $this->dueno->unsetRelation('business');
        Http::fake([
            'crm.bigsysweb.test/api/erp/webhook' => Http::response(['ok' => true], 200),
            'crm.bigsysweb.test/api/v1/users/sync' => Http::response(['data' => ['summary' => ['total' => 1, 'created' => 1, 'updated' => 0, 'errors' => 0], 'results' => []]], 200),
            'crm.bigsysweb.test/api/v1/contacts/sync' => Http::response(['data' => ['summary' => [], 'results' => []]], 200),
            'crm.bigsysweb.test/api/v1/products/sync' => Http::response(['data' => ['summary' => [], 'results' => []]], 200),
            'crm.bigsysweb.test/api/v1/tasks?*' => Http::response(['data' => [
                ['id' => 1, 'title' => 'Llamar a López', 'status' => 'pending', 'due_date' => today()->toDateString() . 'T10:30:00.000Z', 'priority' => 'high'],
                ['id' => 2, 'title' => 'Ya hecha', 'status' => 'completed', 'due_date' => today()->toDateString()],
            ]], 200),
            'crm.bigsysweb.test/api/v1/tasks' => Http::response(['data' => ['id' => 77, 'title' => 'x']], 201),
            'crm.bigsysweb.test/api/v1/appointments?*' => Http::response(['data' => [
                ['id' => 5, 'start_at' => today()->addDay()->toDateString() . 'T14:00:00.000Z', 'status' => 'confirmed', 'service_name_snapshot' => 'Visita técnica'],
                ['id' => 6, 'start_at' => today()->addDay()->toDateString() . 'T16:00:00.000Z', 'status' => 'cancelled', 'service_name_snapshot' => 'Cancelado'],
            ]], 200),
        ]);
    }

    public function test_alta_cambio_de_rol_y_baja_de_usuarios_viajan_al_crm(): void
    {
        $rolVendedor = $this->empresa->roles()->where('slug', 'vendedor')->value('id');
        $this->post('/configuracion/usuarios', ['name' => 'Ana Ventas', 'email' => 'ana@empresa.com', 'password' => 'clave1234', 'password_confirmation' => 'clave1234', 'role_id' => $rolVendedor, 'status' => 'active', 'sucursales' => [['id' => $this->sucursal->id, 'role_id' => $rolVendedor]]])->assertSessionHasNoErrors();
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/v1/users/sync') && $r['items'][0]['email'] === 'ana@empresa.com' && $r['items'][0]['role'] === 'operator' && $r['items'][0]['is_active'] === true && $r['items'][0]['name'] === 'Ana Ventas');
        $ana = \App\Models\User::where('email', 'ana@empresa.com')->first();
        Cache::flush();
        $rolAdmin = $this->empresa->roles()->where('slug', 'administrador')->value('id');
        $this->post("/configuracion/usuarios/{$ana->id}", ['name' => 'Ana Ventas', 'email' => 'ana@empresa.com', 'role_id' => $rolAdmin, 'status' => 'inactive', 'sucursales' => [['id' => $this->sucursal->id, 'role_id' => $rolAdmin]]])->assertSessionHasNoErrors();
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/v1/users/sync') && $r['items'][0]['email'] === 'ana@empresa.com' && $r['items'][0]['is_active'] === false && $r['items'][0]['role'] === 'admin');
    }

    public function test_la_agenda_muestra_tareas_y_turnos_del_crm_con_link_al_crm(): void
    {
        $r = $this->get('/agenda')->assertOk();
        $r->assertInertia(fn($a) => $a->component('Agenda/Index', false)->has('crm', 2)
            ->where('crm.0.tipo', 'tarea')->where('crm.0.titulo', 'Llamar a López')->where('crm.0.fecha', today()->toDateString())->where('crm.0.hora', '10:30')
            ->where('crm.1.tipo', 'turno')->where('crm.1.titulo', 'Visita técnica')->where('crm.1.fecha', today()->addDay()->toDateString())
            ->where('crm.0.url', fn($u) => str_starts_with($u, '/integraciones/crm/ir?a=')));
        // Segunda visita en 5 minutos: no vuelve a pegarle al CRM (caché).
        $antes = count(Http::recorded(fn($r) => str_contains($r->url(), '/api/v1/tasks?')));
        $this->get('/agenda')->assertOk();
        $this->assertSame($antes, count(Http::recorded(fn($r) => str_contains($r->url(), '/api/v1/tasks?'))));
        // Sin integración: sin ítems y sin llamadas.
        $this->empresa->update(['crm_settings' => ['activo' => false]]); $this->dueno->unsetRelation('business'); Cache::flush();
        $this->get('/agenda')->assertInertia(fn($a) => $a->has('crm', 0));
    }

    public function test_recordatorio_de_cobranza_por_el_crm_crea_una_tarea(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente(['name' => 'López SRL', 'dias_pago' => 0]); $cli->update(['crm_external_id' => '55']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 1000]], ['tipo' => 'FA']);
        $f->forceFill(['fecha_vto' => today()->subDays(5)])->save();
        $this->get('/clientes/cobranzas')->assertOk()->assertInertia(fn($a) => $a->component('Clientes/Cobranzas', false)->where('crmActivo', true));
        $this->post("/clientes/cobranzas/{$cli->id}/recordar", ['canal' => 'crm'])->assertSessionHasNoErrors();
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/api/v1/tasks') && $r->method() === 'POST' && str_starts_with($r['title'], 'Cobrar Factura A') && $r['contact_id'] === 55 && $r['priority'] === 'high' && str_contains($r['description'], 'López SRL'));
        $e = Envio::where('canal', 'crm')->first();
        $this->assertNotNull($e); $this->assertSame('enviado', $e->estado); $this->assertSame($cli->id, $e->contact_id);
        // Configuración acepta el canal.
        $this->post('/clientes/cobranzas/configurar', ['activo' => true, 'dias' => [0, 7], 'canales' => ['crm'], 'texto' => 'Hola {cliente}'])->assertSessionHasNoErrors();
        $this->assertSame(['crm'], $this->empresa->fresh()->recordatorios['canales']);
    }
}
