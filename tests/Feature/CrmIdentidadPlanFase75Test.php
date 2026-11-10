<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Integraciones\CrmService;
use App\Services\Suscripciones\SuscripcionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Fase 75 (lado ERP): identidad completa (altas, roles y bajas desde el CRM hacia el ERP) y un solo plan que habilita los dos.
class CrmIdentidadPlanFase75Test extends ErpTestCase
{
    private const WH = 'secreto-webhook-crm-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => 'secreto-compartido-de-prueba-1234567890', 'api_key' => 'bsk_prueba', 'webhook_secreto' => self::WH, 'lista_precios' => 1]]);
        $this->dueno->unsetRelation('business'); Cache::flush();
        Http::fake(['crm.bigsysweb.test/*' => Http::response(['ok' => true, 'data' => []])]);
    }

    private function webhook(string $evento, array $user)
    {
        $cuerpo = json_encode(['event' => $evento, 'data' => ['user' => $user]]);
        auth()->logout();
        return $this->call('POST', "/api/crm/webhook/{$this->empresa->id}", [], [], [], ['HTTP_X_BIGSYSWEB_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $cuerpo, self::WH), 'CONTENT_TYPE' => 'application/json'], $cuerpo);
    }

    public function test_el_alta_el_rol_y_la_baja_hechos_en_el_crm_se_espejan_en_el_erp(): void
    {
        // Alta desde el CRM: nace como vendedor en la sucursal principal.
        $r = $this->webhook('user.created', ['email' => 'Juan@Test.com', 'name' => 'Juan Pérez', 'role' => 'operator', 'is_active' => true])->assertOk()->json();
        $this->assertTrue($r['ok']); $this->assertSame('alta', $r['accion']); $this->assertSame('vendedor', $r['perfil']);
        $juan = User::withoutGlobalScopes()->where('email', 'juan@test.com')->first();
        $this->assertSame($this->empresa->id, $juan->business_id); $this->assertSame('active', $juan->status); $this->assertSame($this->sucursal->id, $juan->current_location_id); $this->assertSame('vendedor', $juan->role->slug);
        $this->assertTrue($juan->locations()->where('business_locations.id', $this->sucursal->id)->exists());

        // Cambio de rol y nombre desde el CRM.
        $r = $this->webhook('user.updated', ['email' => 'juan@test.com', 'name' => 'Juan P. Pérez', 'role' => 'admin'])->assertOk()->json();
        $this->assertSame('actualizado', $r['accion']);
        $juan->refresh(); $this->assertSame('administrador', $juan->role->slug); $this->assertSame('Juan P. Pérez', $juan->name);
        $this->assertSame($juan->role_id, (int) $juan->locations()->first()->pivot->role_id, 'El rol por sucursal acompaña');

        // Baja desde el CRM.
        $this->webhook('user.deactivated', ['email' => 'juan@test.com'])->assertOk()->assertJsonPath('accion', 'baja');
        $this->assertSame('inactive', $juan->fresh()->status);
        // Y vuelta a activar con user.updated is_active.
        $this->webhook('user.updated', ['email' => 'juan@test.com', 'is_active' => true])->assertOk();
        $this->assertSame('active', $juan->fresh()->status);

        // El dueño es intocable desde el CRM.
        $this->webhook('user.deactivated', ['email' => $this->dueno->email])->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertSame('active', $this->dueno->fresh()->status);
        $r = $this->webhook('user.updated', ['email' => $this->dueno->email, 'role' => 'viewer'])->assertStatus(422)->json();
        $this->assertFalse($r['ok']); $this->assertSame('dueno', $this->dueno->fresh()->role->slug); $this->assertStringContainsString('dueño', $r['error']);
        $this->webhook('user.updated', ['email' => $this->dueno->email, 'is_active' => false])->assertStatus(422);
        // El CRM manda el usuario completo con su rol "admin": para el dueño eso no es un cambio de rol, así que el nombre se actualiza igual.
        $this->webhook('user.updated', ['email' => $this->dueno->email, 'name' => 'Dueño Renombrado', 'role' => 'admin', 'is_active' => true])->assertOk()->assertJsonPath('accion', 'actualizado');
        $this->assertSame('Dueño Renombrado', $this->dueno->fresh()->name); $this->assertSame('dueno', $this->dueno->fresh()->role->slug);
        // Baja de alguien que no existe: no es error.
        $this->webhook('user.deleted', ['email' => 'nadie@test.com'])->assertOk()->assertJsonPath('ignorado', 'No existe en el ERP.');
        $this->assertTrue(\App\Models\AuditLog::withoutGlobalScopes()->where('descripcion', 'like', '%desde el CRM%')->exists());
    }

    public function test_el_crm_no_puede_pasar_el_maximo_de_usuarios_del_plan_del_erp(): void
    {
        $chico = Plan::create(['name' => 'Chico', 'slug' => 'chico-test', 'price_monthly' => 10, 'price_yearly' => 100, 'max_users' => 1, 'max_locations' => 1, 'max_products' => 10, 'max_facturas_mes' => 10, 'features' => ['*'], 'is_active' => true]);
        Subscription::where('business_id', $this->empresa->id)->update(['plan_id' => $chico->id]);
        $r = $this->webhook('user.created', ['email' => 'otro@test.com', 'name' => 'Otro', 'role' => 'operator'])->assertStatus(422)->json();
        $this->assertFalse($r['ok']); $this->assertStringContainsString('permite hasta 1 usuario', $r['error']);
        $this->assertFalse(User::withoutGlobalScopes()->where('email', 'otro@test.com')->exists());
    }

    public function test_un_solo_plan_el_erp_le_dice_al_crm_si_lo_incluye_y_le_avisa_cada_cambio(): void
    {
        $conCrm = Plan::create(['name' => 'Negocio + CRM', 'slug' => 'negocio-crm-test', 'price_monthly' => 99, 'price_yearly' => 990, 'max_users' => 10, 'max_locations' => 3, 'max_products' => -1, 'max_facturas_mes' => -1, 'features' => ['comprobantes', 'clientes', 'crm'], 'is_active' => true]);
        $sinCrm = Plan::create(['name' => 'Solo ERP', 'slug' => 'solo-erp-test', 'price_monthly' => 39, 'price_yearly' => 390, 'max_users' => 3, 'max_locations' => 1, 'max_products' => -1, 'max_facturas_mes' => -1, 'features' => ['comprobantes', 'clientes'], 'is_active' => true]);
        $token = $this->dueno->createToken('api')->plainTextToken;

        app(SuscripcionService::class)->activar($this->empresa->fresh(), $conCrm, 'monthly', 'cortesia');
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/erp/webhook') && ($q['evento'] ?? '') === 'plan.cambiado' && $q['datos']['incluye_crm'] === true && $q['datos']['plan'] === 'Negocio + CRM' && $q['datos']['estado'] === 'active');
        auth()->logout();
        $p = $this->withToken($token)->getJson('/api/plan')->assertOk()->json('data');
        $this->assertTrue($p['incluye_crm']); $this->assertSame(10, $p['max_usuarios']); $this->assertSame(1, $p['usuarios_activos']); $this->assertNotNull($p['vence']); $this->assertStringContainsString('/suscripcion', $p['url_suscripcion']);

        // Cambia a un plan sin CRM: el CRM se entera.
        $this->actingAs($this->dueno);
        app(SuscripcionService::class)->activar($this->empresa->fresh(), $sinCrm, 'monthly', 'cortesia');
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/erp/webhook') && ($q['evento'] ?? '') === 'plan.cambiado' && $q['datos']['incluye_crm'] === false && $q['datos']['plan'] === 'Solo ERP');
        $this->dueno->unsetRelation('business');
        $props = $this->get('/configuracion/crm')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['plan']['incluye_crm']); $this->assertSame('Solo ERP', $props['plan']['plan']);

        // Contratado aparte por el superadmin también cuenta.
        $this->empresa->forceFill(['modulos_contratados' => ['crm']])->save();
        $this->assertTrue(CrmService::plan($this->empresa->fresh())['incluye_crm']);

        // Suspensión por falta de pago → bloqueada, y el CRM lo sabe.
        app(SuscripcionService::class)->suspender($this->empresa->fresh(), 'Falta de pago');
        Http::assertSent(fn($q) => str_ends_with($q->url(), '/api/erp/webhook') && ($q['evento'] ?? '') === 'plan.cambiado' && $q['datos']['estado'] === 'bloqueada' && ! empty($q['datos']['motivo']));
        // Bigcito también lo cuenta.
        $m = app(\App\Mcp\Catalogo::class)->llamar($this->dueno->fresh(), 'estado_crm', [], 'asistente');
        $this->assertSame('bloqueada', $m['plan']['estado']);
    }
}
