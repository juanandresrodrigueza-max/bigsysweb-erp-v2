<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Services\Producto\UsoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

// Fase 22 · Producto: centro de ayuda y tour, contador con varias empresas, API documentada y métricas de uso.
class ProductoTest extends ErpTestCase
{
    public function test_centro_de_ayuda_lista_articulos_busca_y_muestra_la_guia_de_la_pantalla_actual(): void
    {
        $this->get('/ayuda')->assertOk()->assertInertia(fn($p) => $p->component('Ayuda', false)->has('articulos', 23)->where('articulos.0.slug', 'implementacion')->where('articulos.1.slug', 'primeros-pasos'));
        $this->get('/ayuda/facturar')->assertOk()->assertInertia(fn($p) => $p->component('Ayuda', false)->where('articulo.titulo', 'Facturar, presupuestar y remitir')->where('articulo.secciones.0.titulo', 'La factura paso a paso'));
        $this->get('/ayuda/no-existe')->assertNotFound();
        // La búsqueda devuelve la sección exacta, sin acentos y por raíz de palabra.
        $this->get('/ayuda?q=anular factura')->assertInertia(fn($p) => $p->component('Ayuda', false)->where('resultados.0.slug', 'preguntas-frecuentes')->where('resultados.0.seccion', '¿Cómo anulo una factura emitida?')->has('indice', 23)->where('indice.0.slug', 'implementacion'));
        $this->getJson('/ayuda/buscar?q=rebot')->assertOk()->assertJsonPath('0.slug', 'cobrar')->assertJsonPath('0.seccion', 'Errores comunes')->assertJsonPath('0.url', '/ayuda/cobrar#errores-comunes');
        $this->getJson('/ayuda/buscar?q=ARQUEO')->assertJsonPath('0.slug', 'fondos')->assertJsonPath('0.seccion', 'Caja');
        $this->getJson('/ayuda/buscar?q=codigo de barras balanza')->assertJsonPath('0.slug', 'punto-de-venta')->assertJsonPath('0.seccion', 'Balanza e impresora');
        $this->get('/ayuda/fondos')->assertInertia(fn($p) => $p->where('articulo.secciones.0.ancla', 'caja'));
        $this->assertStringContainsString('<h2 id="caja">', app(\App\Services\Producto\AyudaService::class)->articulo('fondos')['html']);
        // Pregunta en lenguaje natural: sin IA devuelve las secciones; con IA responde citando fuentes.
        $this->postJson('/ayuda/preguntar', ['q' => '¿cómo anulo una factura?'])->assertOk()->assertJsonPath('ia', false)->assertJsonPath('respuesta', null)->assertJsonPath('fuentes.0.slug', 'preguntas-frecuentes');
        config(['services.anthropic.api_key' => 'sk-test']);
        \Illuminate\Support\Facades\Http::fake(['api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response(['content' => [['text' => 'Con una nota de crédito: desde la factura, Convertir → Nota de crédito.']]])]);
        $this->postJson('/ayuda/preguntar', ['q' => '¿cómo anulo una factura?'])->assertOk()->assertJsonPath('ia', true)->assertJsonPath('respuesta', 'Con una nota de crédito: desde la factura, Convertir → Nota de crédito.')->assertJsonCount(6, 'fuentes');
        \Illuminate\Support\Facades\Http::assertSent(fn($r) => str_contains($r['messages'][0]['content'], '¿Cómo anulo una factura emitida?'));
        $this->getJson('/ayuda/contexto?ruta=/comprobantes/nuevo')->assertOk()->assertJsonPath('articulo.slug', 'facturar')->assertJsonCount(6, 'tour');
        $this->getJson('/ayuda/contexto?ruta=/retail')->assertJsonPath('articulo.slug', 'punto-de-venta');
        $this->getJson('/ayuda/contexto?ruta=/obras/3')->assertJsonPath('articulo.slug', 'obras');
        $this->getJson('/ayuda/contexto?ruta=/ruta-inexistente')->assertJsonPath('articulo', null)->assertJsonCount(6, 'sugeridos');
        foreach (['/comprobantes/pedidos' => 'ventas-avanzadas', '/proveedores/compras' => 'compras', '/sueldos' => 'sueldos', '/servicios/2' => 'servicio-tecnico', '/gastronomia/cocina' => 'gastronomia', '/hoteleria' => 'hoteleria', '/agenda' => 'agenda', '/configuracion/tienda' => 'tienda-y-canales', '/admin/empresas' => 'administracion-bigsys', '/primeros-pasos' => 'implementacion', '/contable/iva' => 'contable', '/estadisticas/rentabilidad' => 'estadisticas', '/fondos/cheques' => 'fondos', '/stock/inventario' => 'stock', '/clientes/cobranzas' => 'cobrar', '/alertas' => 'alertas-y-asistente'] as $ruta => $slug) $this->getJson('/ayuda/contexto?ruta=' . $ruta)->assertJsonPath('articulo.slug', $slug, "Ruta {$ruta}");
        // Manual completo: todos los artículos en una página imprimible, y los pasos del onboarding con su guía.
        $this->get('/ayuda/manual')->assertOk()->assertSee('Manual de usuario')->assertSee('1. Guía de implementación')->assertSee('id="preguntas-frecuentes"', false);
        $this->get('/primeros-pasos')->assertInertia(fn($pg) => $pg->component('Onboarding', false)->where('pasos.0.ayuda', 'implementacion')->where('pasos.1.ayuda', 'guia-arca'));
        $this->get('/ayuda/guia/arca')->assertOk()->assertInertia(fn($p) => $p->component('Ayuda', false)->where('articulo.slug', 'guia-arca'));
        $this->get('/ayuda/guia/../.env')->assertNotFound();
    }

    public function test_el_tour_se_muestra_una_vez_por_usuario(): void
    {
        $this->get('/dashboard')->assertInertia(fn($p) => $p->has('tour', 6)->where('tour.0.sel', '[data-tour="menu"]'));
        $this->postJson('/ayuda/tour-visto')->assertOk();
        $this->assertNotNull($this->dueno->fresh()->tour_visto_en);
        $this->get('/dashboard')->assertInertia(fn($p) => $p->where('tour', null));
        $this->post('/ayuda/tour-reiniciar')->assertSessionHas('success');
        $this->get('/dashboard')->assertInertia(fn($p) => $p->has('tour', 6));
    }

    public function test_contador_con_el_mismo_email_entra_a_varias_empresas_y_cambia_entre_ellas(): void
    {
        [$otra, , $dueno2] = $this->crearEmpresa('Otra Empresa', 'otra');
        Contact::create(['business_id' => $otra->id, 'type' => 'customer', 'name' => 'Cliente de Otra', 'condicion_iva' => 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1]);
        $this->cliente(['name' => 'Cliente de Test']);
        // Empresa 1 invita al contador (usuario nuevo).
        $this->post('/contable/contador/invitar', ['name' => 'Estudio Pérez', 'email' => 'estudio@perez.com', 'password' => 'Estudio2026x'])->assertSessionHas('success');
        $contador = User::where('email', 'estudio@perez.com')->firstOrFail();
        $this->assertSame($this->empresa->id, $contador->business_id);
        // Empresa 2 invita al mismo email: no se crea otro usuario, se le da acceso.
        Auth::login($dueno2); $this->actingAs($dueno2);
        $this->post('/contable/contador/invitar', ['email' => 'estudio@perez.com'])->assertSessionHas('success');
        $this->assertSame(1, User::where('email', 'estudio@perez.com')->count());
        $this->assertSame(1, $contador->empresas()->count());
        $this->get('/contable/contador')->assertInertia(fn($p) => $p->component('Contable/Contador', false)->where('contadores.0.externo', true)->where('contadores.0.empresas', 2));

        // El contador ve sus dos empresas y cambia de una a otra: cambia el aislamiento de datos y el rol.
        Auth::login($contador->fresh()); $this->actingAs($contador->fresh());
        $this->get('/contador/empresas')->assertOk()->assertInertia(fn($p) => $p->component('Contable/MisEmpresas', false)->has('empresas', 2)->where('empresas.0.nombre', 'Empresa Test')->where('empresas.0.actual', true));
        $this->get('/dashboard')->assertInertia(fn($p) => $p->has('empresas', 2));
        $this->assertTrue(Contact::where('name', 'Cliente de Test')->exists()); $this->assertFalse(Contact::where('name', 'Cliente de Otra')->exists());
        $this->post("/empresa/{$otra->id}")->assertRedirect('/dashboard');
        $c = $contador->fresh(); Auth::login($c); $this->actingAs($c);
        $this->assertSame($otra->id, $c->business_id);
        $this->assertSame(Role::withoutGlobalScopes()->where('business_id', $otra->id)->where('slug', 'contador')->value('id'), $c->role_id);
        $this->assertTrue(Contact::where('name', 'Cliente de Otra')->exists()); $this->assertFalse(Contact::where('name', 'Cliente de Test')->exists());
        $this->assertTrue($c->puede('contable', 'ver')); $this->assertFalse($c->puede('comprobantes', 'crear'));
        // Vuelve a la empresa de origen con su rol original.
        $this->post("/empresa/{$this->empresa->id}")->assertRedirect('/dashboard');
        $c = $contador->fresh(); $this->assertSame($this->empresa->id, $c->business_id);
        $this->assertSame(Role::withoutGlobalScopes()->where('business_id', $this->empresa->id)->where('slug', 'contador')->value('id'), $c->role_id);
        // Una empresa ajena: prohibido.
        [$ajena] = $this->crearEmpresa('Ajena', 'ajena');
        $this->post("/empresa/{$ajena->id}")->assertForbidden();
        // Al entrar, un contador con varias empresas cae en "Mis empresas".
        $this->post('/logout'); $this->post('/login', ['email' => 'estudio@perez.com', 'password' => 'Estudio2026x'])->assertRedirect('/contador/empresas');
        // Empresa 2 le quita el acceso.
        Auth::login($dueno2); $this->actingAs($dueno2);
        $this->delete("/contable/contador/acceso/{$contador->id}")->assertSessionHas('success');
        $this->assertSame(0, $contador->fresh()->empresas()->where('businesses.id', $otra->id)->count());
    }

    public function test_la_api_esta_documentada_desde_las_rutas_reales(): void
    {
        Auth::logout();
        $this->get('/api/docs')->assertOk()->assertSee('Artículos')->assertSee('/api/products')->assertSee('Bearer')->assertSee('Guía de uso', false)->assertSee('X-BigSys-Firma');
        $j = $this->getJson('/api/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3')->json();
        $this->assertArrayHasKey('/api/products', $j['paths']);
        $this->assertSame([['bearerAuth' => []]], $j['paths']['/api/products']['get']['security']);
        $this->assertArrayNotHasKey('security', $j['paths']['/api/auth/login']['post'], 'El login es público');
        $this->assertSame('Listar', $j['paths']['/api/products']['get']['summary']);
        $this->assertSame('id', $j['paths']['/api/products/{product}']['get']['parameters'][0]['name'] === 'product' ? 'id' : 'x');
        $this->assertGreaterThan(40, count($j['paths']));
    }

    public function test_se_registra_el_uso_por_modulo_y_se_ve_en_usuarios_y_en_el_panel_de_bigsys(): void
    {
        $this->get('/dashboard')->assertOk(); $this->get('/comprobantes')->assertOk(); $this->get('/comprobantes')->assertOk();
        $this->post('/clientes', ['name' => 'Nuevo', 'condicion_iva' => 'Consumidor Final', 'lista_precios' => 1]);
        $this->getJson('/buscar/global?q=abc'); // no cuenta
        $filas = DB::table('uso_diario')->where('user_id', $this->dueno->id)->get()->keyBy('modulo');
        $this->assertSame(1, (int) $filas['dashboard']->vistas); $this->assertSame(2, (int) $filas['comprobantes']->vistas);
        $this->assertSame(1, (int) $filas['clientes']->acciones); $this->assertArrayNotHasKey('buscar', $filas->all());
        $r = app(UsoService::class)->empresa($this->empresa, 30);
        $this->assertSame('comprobantes', $r['modulos'][0]['modulo']); $this->assertSame(1, $r['usuarios_activos']); $this->assertSame(1, $r['dias_activos']);
        $this->get('/configuracion/usuarios')->assertInertia(fn($p) => $p->component('Configuracion/Usuarios', false)->where('usuarios.0.actividad.dias', 1));

        $admin = User::create(['name' => 'BigSys', 'email' => 'admin@bigsys.test', 'password' => 'password', 'status' => 'active', 'is_superadmin' => true]);
        Auth::login($admin); $this->actingAs($admin);
        $this->get('/admin/uso')->assertOk()->assertInertia(fn($p) => $p->component('Superadmin/Uso', false)->where('kpis.activas_periodo', 1)->where('ranking.0.nombre', 'Empresa Test')->where('modulos.0.modulo', 'comprobantes'));
        $this->get("/admin/empresas/{$this->empresa->id}")->assertInertia(fn($p) => $p->component('Superadmin/EmpresaVer', false)->where('usoDetalle.usuarios_activos', 1));
        $this->assertSame(0, DB::table('uso_diario')->where('user_id', $admin->id)->count(), 'El superadmin sin empresa no genera métricas');
    }
}
