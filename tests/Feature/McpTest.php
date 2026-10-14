<?php

namespace Tests\Feature;

use App\Models\Cobro;
use App\Models\IaLlamada;
use App\Models\McpConexion;
use App\Models\MovimientoFondos;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\ErpTestCase;

// Fase 28: el ERP como servidor MCP. Protocolo, aislamiento por empresa, modos de la conexión, propuestas con confirmación y OAuth.
class McpTest extends ErpTestCase
{
    private function token(User $u, string $modo = 'lectura'): string
    {
        $t = $u->createToken('MCP · test', ['mcp']);
        McpConexion::withoutGlobalScopes()->create(['business_id' => $u->business_id, 'user_id' => $u->id, 'nombre' => 'test', 'token_id' => $t->accessToken->id, 'modo' => $modo]);
        return $t->plainTextToken;
    }

    private function rpc(string $token, string $metodo, array $params = [], $id = 1)
    {
        Auth::forgetGuards(); // cada pedido MCP entra sin sesión
        return $this->withHeader('Authorization', "Bearer {$token}")->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo, 'params' => $params]);
    }

    private function herramienta(string $token, string $nombre, array $args = []): array
    {
        return json_decode($this->rpc($token, 'tools/call', ['name' => $nombre, 'arguments' => $args])->assertOk()->json('result.content.0.text'), true);
    }

    public function test_protocolo_initialize_listas_y_errores(): void
    {
        $tk = $this->token($this->dueno);
        $this->rpc($tk, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'x']])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26')->assertJsonPath('result.serverInfo.name', 'bigsysweb-erp');
        $tools = collect($this->rpc($tk, 'tools/list')->json('result.tools'))->keyBy('name');
        $this->assertTrue($tools->has('ventas_periodo') && $tools->has('saldos_cuentas') && $tools->has('que_reponer'));
        $this->assertFalse($tools->has('registrar_cobro'), 'En modo lectura no se ofrecen acciones');
        $this->assertFalse($tools->has('confirmar_accion'));
        $this->assertTrue($tools['ventas_periodo']['annotations']['readOnlyHint']);
        $this->assertCount(4, $this->rpc($tk, 'prompts/list')->json('result.prompts'));
        $this->assertStringContainsString('ventas_periodo', $this->rpc($tk, 'prompts/get', ['name' => 'cierre_del_dia'])->json('result.messages.0.content.text'));
        $this->assertStringContainsString('Empresa Test', $this->rpc($tk, 'resources/read', ['uri' => 'erp://negocio'])->json('result.contents.0.text'));
        $this->rpc($tk, 'no/existe')->assertJsonPath('error.code', -32601);
        // Notificación: sin respuesta. Lote: una respuesta por pedido.
        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tk}")->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $lote = $this->withHeader('Authorization', "Bearer {$tk}")->postJson('/mcp', [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']])->json();
        $this->assertCount(2, $lote);
        $this->get('/mcp')->assertStatus(405);
    }

    public function test_sin_token_valido_devuelve_401_con_metadata_oauth(): void
    {
        Auth::forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401)->assertHeader('WWW-Authenticate');
        // Un token de la API común (sin habilidad mcp) no sirve.
        $api = $this->dueno->createToken('api')->plainTextToken;
        $this->rpc($api, 'ping')->assertStatus(401);
        // Token revocado: no sirve más.
        $tk = $this->token($this->dueno);
        $this->rpc($tk, 'ping')->assertOk();
        McpConexion::withoutGlobalScopes()->update(['revocada_en' => now()]);
        $this->rpc($tk, 'ping')->assertStatus(401);
        // El superadmin nunca entra por MCP.
        $sa = User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->rpc($this->token($sa), 'ping')->assertStatus(401);
    }

    public function test_el_token_del_mcp_no_entra_a_la_api_rest(): void
    {
        // Un token «Solo consultas» no puede escribir (ni leer) por la API común: sólo sirve en /mcp.
        $tk = $this->token($this->dueno);
        $api = $this->dueno->createToken('api')->plainTextToken;
        $conToken = function (string $t) {
            Auth::forgetGuards();
            $this->flushSession(); // un cliente de la API entra sin la sesión del navegador
            return $this->withHeader('Authorization', "Bearer {$t}");
        };
        $conToken($tk)->getJson('/api/business')->assertStatus(403);
        $conToken($tk)->postJson('/api/products', ['name' => 'Colado por el MCP'])->assertStatus(403);
        $conToken($tk)->getJson('/api/auth/me')->assertStatus(403);
        $this->assertSame(0, \App\Models\Product::withoutGlobalScopes()->where('name', 'Colado por el MCP')->count());
        $this->rpc($tk, 'ping')->assertOk(); // en /mcp sigue andando
        // El token de API común sigue entrando.
        $conToken($api)->getJson('/api/business')->assertOk();
    }

    public function test_cada_empresa_ve_solo_sus_datos(): void
    {
        $this->cliente(['name' => 'Cliente de Test']);
        [$otra, , $duenoB] = $this->crearEmpresa('Otra Empresa', 'otra');
        $this->cliente(['name' => 'Cliente de Otra', 'business_id' => $otra->id]);
        $tkA = $this->token($this->dueno); $tkB = $this->token($duenoB);
        $a = $this->herramienta($tkA, 'buscar_clientes', ['texto' => 'Cliente']);
        $this->assertSame(['Cliente de Test'], array_column($a['clientes'], 'nombre'));
        $b = $this->herramienta($tkB, 'buscar_clientes', ['texto' => 'Cliente']);
        $this->assertSame(['Cliente de Otra'], array_column($b['clientes'], 'nombre'));
        $this->assertStringContainsString('No encuentro', $this->herramienta($tkB, 'ficha_cliente', ['cliente' => 'Cliente de Test'])['error']);
    }

    public function test_acciones_proponen_y_se_ejecutan_solo_al_confirmar_segun_el_modo(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['name' => 'Ferretería López']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 10000]]);
        // Modo lectura: la acción se rechaza aunque la IA la llame igual.
        $lect = $this->token($this->dueno, 'lectura');
        $r = $this->rpc($lect, 'tools/call', ['name' => 'registrar_cobro', 'arguments' => ['cliente' => 'López', 'monto' => 5000]])->assertOk();
        $this->assertTrue($r->json('result.isError')); $this->assertStringContainsString('Solo consultas', $r->json('result.content.0.text'));

        $acc = $this->token($this->dueno, 'acciones');
        $prop = $this->herramienta($acc, 'registrar_cobro', ['cliente' => 'López', 'monto' => 5000, 'medio' => 'transferencia']);
        $this->assertNotEmpty($prop['propuesta_id']); $this->assertStringContainsString('Ferretería López', $prop['titulo']);
        $this->assertSame(0, Cobro::count(), 'Proponer no hace nada');
        $ok = $this->herramienta($acc, 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']]);
        $this->assertTrue($ok['ok']); $this->assertSame(1, Cobro::count());
        $this->assertEqualsWithDelta(7100, (float) $f->fresh()->saldo, 0.01);
        $this->assertStringContainsString('ya está ejecutada', $this->herramienta($acc, 'confirmar_accion', ['propuesta_id' => $prop['propuesta_id']])['error'], 'No se ejecuta dos veces');
        $this->assertTrue(\App\Models\AuditLog::where('accion', 'ia_ejecuto')->exists());

        // Acción delicada: el modo "acciones" no alcanza.
        $tools = collect($this->rpc($acc, 'tools/list')->json('result.tools'))->pluck('name');
        $this->assertContains('registrar_gasto', $tools); $this->assertNotContains('transferir_entre_cuentas', $tools);
        $this->assertArrayHasKey('error', $this->herramienta($acc, 'transferir_entre_cuentas', ['desde' => 'caja', 'hasta' => 'banco', 'monto' => 100]));
        $del = $this->token($this->dueno, 'delicadas');
        $t = $this->herramienta($del, 'transferir_entre_cuentas', ['desde' => 'banco', 'hasta' => 'caja', 'monto' => 1000]);
        $this->herramienta($del, 'confirmar_accion', ['propuesta_id' => $t['propuesta_id']]);
        $this->assertSame(2, MovimientoFondos::where('origen', 'transferencia')->count());

        // Una propuesta vencida no se ejecuta; otra cancelada tampoco.
        $g = $this->herramienta($acc, 'registrar_gasto', ['concepto' => 'Flete', 'monto' => 500]);
        $this->travel(31)->minutes();
        $this->assertStringContainsString('venció', $this->herramienta($acc, 'confirmar_accion', ['propuesta_id' => $g['propuesta_id']])['error']);
        $this->assertSame(0, MovimientoFondos::where('origen', 'gasto')->count());
        $this->assertTrue(IaLlamada::where('herramienta', 'registrar_cobro')->where('ok', true)->exists());
        $this->assertAsientosBalancean();
    }

    public function test_respeta_los_permisos_del_rol(): void
    {
        $vend = $this->usuarioConRol('vendedor');
        $tk = $this->token($vend, 'delicadas');
        $tools = collect($this->rpc($tk, 'tools/list')->json('result.tools'))->pluck('name');
        $this->assertNotContains('registrar_gasto', $tools); $this->assertNotContains('saldos_cuentas', $tools);
        $this->assertStringContainsString('permiso', $this->herramienta($tk, 'saldos_cuentas')['error']);
    }

    public function test_cada_herramienta_de_consulta_responde(): void
    {
        $this->articulo(['name' => 'Cemento', 'stock_inicial' => 5, 'stock_min' => 10]); $this->cliente();
        $tk = $this->token($this->dueno);
        $sin = ['buscar_clientes' => ['texto' => 'a'], 'ficha_cliente' => ['cliente' => 'Cliente'], 'cuenta_corriente_cliente' => ['cliente' => 'Cliente'], 'buscar_articulos' => ['texto' => 'Cem'], 'stock_articulo' => ['articulo' => 'Cemento'],
            'buscar_serie' => ['serie' => 'X'], 'buscar_proveedores' => ['texto' => 'a'], 'ver_comprobante' => ['comprobante' => '1'], 'movimientos_cuenta' => ['cuenta' => 'caja'], 'buscar_comprobantes' => ['texto' => '1']];
        $consultas = collect($this->rpc($tk, 'tools/list')->json('result.tools'))->where('annotations.readOnlyHint', true)->pluck('name');
        $this->assertGreaterThan(25, $consultas->count());
        foreach ($consultas as $n) {
            $r = $this->rpc($tk, 'tools/call', ['name' => $n, 'arguments' => $sin[$n] ?? []])->assertOk();
            $this->assertNotNull(json_decode($r->json('result.content.0.text'), true), $n);
        }
        $this->assertContains('Cemento', array_column($this->herramienta($tk, 'que_reponer')['articulos'], 'nombre'));
    }

    public function test_flujo_oauth_completo_con_pkce_y_refresh(): void
    {
        Auth::forgetGuards();
        $this->getJson('/.well-known/oauth-protected-resource')->assertJsonPath('resource', url('/mcp'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertJsonPath('code_challenge_methods_supported', ['S256']);
        $this->postJson('/oauth/register', ['client_name' => 'Malo', 'redirect_uris' => ['http://evil.com/cb']])->assertStatus(400);
        $cli = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertStatus(201)->json();

        $ver = Str::random(64); $chal = rtrim(strtr(base64_encode(hash('sha256', $ver, true)), '+/', '-_'), '=');
        $q = ['response_type' => 'code', 'client_id' => $cli['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'code_challenge' => $chal, 'code_challenge_method' => 'S256', 'state' => 'xyz'];
        $this->actingAs($this->dueno)->get('/oauth/authorize?' . http_build_query($q))->assertOk()->assertInertia(fn($i) => $i->component('Mcp/Autorizar', false)->where('cliente', 'Claude'));
        $this->get('/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://otro.com/cb'] + $q))->assertInertia(fn($i) => $i->has('error'));
        $loc = $this->post('/oauth/authorize', $q + ['decision' => 'permitir', 'modo' => 'acciones'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?', $loc);
        $this->assertStringContainsString('error=access_denied', $this->post('/oauth/authorize', $q + ['decision' => 'rechazar'])->headers->get('Location'));
        parse_str(parse_url($loc, PHP_URL_QUERY), $vuelta);
        $this->assertSame('xyz', $vuelta['state']);

        Auth::forgetGuards();
        $base = ['grant_type' => 'authorization_code', 'code' => $vuelta['code'], 'client_id' => $cli['client_id'], 'redirect_uri' => $q['redirect_uri']];
        $this->post('/oauth/token', $base + ['code_verifier' => 'otro'])->assertStatus(400);
        // El código se consumió en el intento fallido: se pide otro.
        $this->actingAs($this->dueno);
        $loc = $this->post('/oauth/authorize', $q + ['decision' => 'permitir', 'modo' => 'acciones'])->headers->get('Location');
        parse_str(parse_url($loc, PHP_URL_QUERY), $vuelta2);
        Auth::forgetGuards();
        $tok = $this->post('/oauth/token', ['code' => $vuelta2['code'], 'code_verifier' => $ver] + $base)->assertOk()->json();
        $this->assertSame('Bearer', $tok['token_type']);
        $this->post('/oauth/token', ['code' => $vuelta2['code'], 'code_verifier' => $ver] + $base)->assertStatus(400, 'El código es de un solo uso');

        $con = McpConexion::withoutGlobalScopes()->firstWhere('client_id', $cli['client_id']);
        $this->assertSame('acciones', $con->modo);
        $tools = collect($this->rpc($tok['access_token'], 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('registrar_cobro', $tools);

        Auth::forgetGuards();
        $nuevo = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $cli['client_id']])->assertOk()->json();
        $this->rpc($tok['access_token'], 'ping')->assertStatus(401);
        $this->rpc($nuevo['access_token'], 'ping')->assertOk();
        Auth::forgetGuards();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token']])->assertStatus(400, 'El refresh rota');
    }

    public function test_pantalla_de_conexiones_token_personal_modo_y_revocar(): void
    {
        $this->post('/conexiones-ia/token', ['nombre' => 'Claude Code', 'modo' => 'lectura'])->assertRedirect()->assertSessionHas('tokenNuevo');
        $tk = session('tokenNuevo');
        $con = McpConexion::firstWhere('nombre', 'Claude Code');
        $this->rpc($tk, 'ping')->assertOk();
        $this->actingAs($this->dueno)->get('/conexiones-ia')->assertOk()->assertInertia(fn($i) => $i->component('Mcp/Conexiones', false)->has('conexiones', 1)->where('url', url('/mcp')));
        $this->post("/conexiones-ia/{$con->id}/modo", ['modo' => 'acciones'])->assertRedirect();
        $this->assertSame('acciones', $con->fresh()->modo);
        // Otro usuario (no dueño) no puede tocar la conexión del dueño.
        $this->actingAs($this->usuarioConRol('vendedor'))->post("/conexiones-ia/{$con->id}/revocar")->assertStatus(403);
        $this->actingAs($this->dueno)->post("/conexiones-ia/{$con->id}/revocar")->assertRedirect();
        $this->rpc($tk, 'ping')->assertStatus(401);
    }
}
