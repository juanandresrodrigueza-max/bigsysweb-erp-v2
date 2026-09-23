<?php

namespace Tests\Feature;

use App\Services\Integraciones\CrmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// Integración CRM · etapa 1: pasar de un sistema al otro ya logueado con un token firmado de un solo uso.
class CrmHandoffTest extends ErpTestCase
{
    private const SECRETO = 'secreto-compartido-de-prueba-1234567890';

    private function activar(): void
    {
        $this->empresa->update(['cuit' => '30-71234567-8', 'crm_settings' => ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => self::SECRETO, 'api_key' => 'bsk_prueba']]);
        $this->dueno->unsetRelation('business');
    }

    public function test_configurar_generar_secreto_y_probar_conexion(): void
    {
        $this->get('/configuracion/crm')->assertOk()->assertInertia(fn($a) => $a->component('Configuracion/Crm', false)->where('listo', false)->where('config.secreto_set', false));
        // Sin CUIT no se puede activar.
        $this->empresa->update(['cuit' => null]); $this->dueno->unsetRelation('business');
        $this->post('/configuracion/crm', ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => self::SECRETO])->assertSessionHasErrors('activo');
        $this->empresa->update(['cuit' => '30-71234567-8']); $this->dueno->unsetRelation('business');
        $this->post('/configuracion/crm', ['activo' => true, 'url' => 'https://crm.bigsysweb.test/', 'generar_secreto' => true, 'api_key' => 'bsk_prueba'])->assertSessionHas('crm_secreto_nuevo');
        $c = CrmService::config($this->empresa->fresh());
        $this->assertTrue($c['activo']); $this->assertSame('https://crm.bigsysweb.test', $c['url']); $this->assertSame(48, strlen($c['secreto']));
        $this->assertStringNotContainsString('bsk_prueba', \DB::table('businesses')->where('id', $this->empresa->id)->value('crm_settings'), 'Las credenciales quedan cifradas');
        // Guardar sin tocar las claves no las borra.
        $this->post('/configuracion/crm', ['activo' => true, 'url' => 'https://crm.bigsysweb.test', 'secreto' => '', 'api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('bsk_prueba', CrmService::config($this->empresa->fresh())['api_key']);
        // Probar conexión pega a /api/v1/me con la clave.
        Http::fake(['crm.bigsysweb.test/api/v1/me' => Http::response(['data' => ['company' => ['name' => 'Empresa CRM']]], 200)]);
        $this->post('/configuracion/crm/probar')->assertSessionHas('success');
        Http::assertSent(fn($r) => $r->hasHeader('Authorization', 'Bearer bsk_prueba'));
        // Menú con botón CRM externo.
        $this->get('/dashboard')->assertInertia(fn($a) => $a->where('nav', fn($nav) => collect($nav)->flatMap(fn($g) => $g['items'])->contains(fn($i) => $i['key'] === 'crm' && ($i['externo'] ?? false))));
    }

    public function test_ir_al_crm_manda_un_token_firmado_con_cuit_rol_y_vencimiento(): void
    {
        $this->activar();
        $r = $this->get('/integraciones/crm/ir?a=/contacts/12')->assertRedirect();
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://crm.bigsysweb.test/api/erp/sso?t=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $v = CrmService::verificar($q['t'], self::SECRETO);
        $this->assertTrue($v['ok'], $v['motivo'] ?? '');
        $p = $v['payload'];
        $this->assertSame('erp', $p['origen']); $this->assertSame('30712345678', $p['cuit']); $this->assertSame($this->dueno->email, $p['email']); $this->assertSame('admin', $p['rol']); $this->assertSame('/contacts/12', $p['a']);
        $this->assertLessThanOrEqual(time() + 60, $p['exp']);
        // El mismo token no sirve dos veces, y con otro secreto no pasa.
        $this->assertFalse(CrmService::verificar($q['t'], self::SECRETO)['ok']);
        $this->assertStringContainsString('firma', CrmService::verificar($q['t'], 'otro-secreto-cualquiera-12345')['motivo']);
        // Un vendedor va como operador.
        $this->actingAs($this->usuarioConRol('vendedor'))->get('/integraciones/crm/ir')->assertRedirect();
        // Sin integración: 404.
        $this->empresa->update(['crm_settings' => ['activo' => false]]); $this->dueno->unsetRelation('business');
        $this->actingAs($this->dueno)->get('/integraciones/crm/ir')->assertNotFound();
    }

    public function test_entrar_desde_el_crm_abre_sesion_por_cuit_y_email(): void
    {
        $this->activar();
        Auth::logout();
        $token = fn(array $extra = []) => CrmService::codificar(array_merge(['origen' => 'crm', 'email' => $this->dueno->email, 'cuit' => '30712345678', 'rol' => 'admin', 'nonce' => uniqid('', true), 'exp' => time() + 60, 'a' => '/clientes'], $extra), self::SECRETO);
        $this->get('/integraciones/crm/entrar?t=' . urlencode($token()))->assertRedirect('/clientes');
        $this->assertAuthenticatedAs($this->dueno);
        // Usuario que no existe en el ERP: página clara, sin sesión.
        Auth::logout();
        $this->get('/integraciones/crm/entrar?t=' . urlencode($token(['email' => 'nadie@otra.com'])))->assertStatus(403)->assertSee('no existe en el ERP');
        $this->assertGuest();
        // CUIT de otra empresa, token vencido, firma mala.
        $this->get('/integraciones/crm/entrar?t=' . urlencode($token(['cuit' => '30500000001'])))->assertStatus(403);
        $this->get('/integraciones/crm/entrar?t=' . urlencode($token(['exp' => time() - 5])))->assertStatus(403)->assertSee('venció');
        $this->get('/integraciones/crm/entrar?t=' . urlencode(CrmService::codificar(['origen' => 'crm', 'email' => $this->dueno->email, 'cuit' => '30712345678', 'nonce' => 'x', 'exp' => time() + 60], 'secreto-equivocado-123456')))->assertStatus(403)->assertSee('firma');
        $this->assertGuest();
    }
}
