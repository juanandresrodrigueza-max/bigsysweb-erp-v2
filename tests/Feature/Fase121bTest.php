<?php

namespace Tests\Feature;

use App\Models\ArcaAutorizacion;
use App\Models\AuditLog;
use App\Services\Afip\ArcaAutorizaciones;
use App\Services\Afip\ArcaPlataforma;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 121.1: lo legal de facturar a través de BigSys. Autorización expresa con constancia, revocación, baja y vigilancia
// del certificado; y los textos de los términos y la privacidad.
class Fase121bTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa->update(['cuit' => '30-70012345-2', 'razon_social' => 'Corralón Test S.R.L.']);
        // ARCA contesta que la delegación está hecha (y el certificado consigue su ticket).
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'LoginCms')) return Http::response('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><loginCmsResponse><loginCmsReturn>' . htmlspecialchars('<loginTicketResponse><header><expirationTime>' . now()->addHours(12)->toIso8601String() . '</expirationTime></header><credentials><token>T</token><sign>S</sign></credentials></loginTicketResponse>') . '</loginCmsReturn></loginCmsResponse></soapenv:Body></soapenv:Envelope>');
            return Http::response('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FEParamGetPtosVentaResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FEParamGetPtosVentaResult><ResultGet><PtoVenta><Nro>1</Nro><EmisionTipo>CAE</EmisionTipo><Bloqueado>N</Bloqueado><FchBaja>NULL</FchBaja></PtoVenta></ResultGet></FEParamGetPtosVentaResult></FEParamGetPtosVentaResponse></soap:Body></soap:Envelope>');
        });
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'AR', 'organizationName' => 'BIGSYS S.R.L.', 'commonName' => 'bigsyserp', 'serialNumber' => 'CUIT 30712345671'], $k, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $k, 365, ['digest_alg' => 'sha256']), $cert); openssl_pkey_export($k, $key);
        ArcaPlataforma::cargar($cert, $key, true);
    }

    private function activar(array $extra = [], ?string $ua = 'Navegador de prueba')
    {
        return $this->withHeaders(['User-Agent' => $ua])->post('/configuracion/afip/bigsys', array_merge(['activo' => true, 'acepto' => true, 'version' => ArcaAutorizaciones::VERSION], $extra));
    }

    public function test_para_activar_el_duenio_acepta_el_texto_y_queda_constancia_completa(): void
    {
        // La pantalla muestra el texto con la empresa y BigSys.
        $p = $this->get('/configuracion/puntos-venta')->viewData('page')['props']['arca'];
        $this->assertSame(ArcaAutorizaciones::VERSION, $p['autorizacion']['version']); $this->assertTrue($p['puede_aceptar']); $this->assertNull($p['autorizacion']['ultima']);
        $this->assertStringContainsString('Corralón Test S.R.L. (CUIT 30-70012345-2) autoriza a BIGSYS S.R.L. (CUIT 30-71234567-1)', $p['autorizacion']['texto']);
        $this->assertStringContainsString('la empresa sigue siendo el emisor', $p['autorizacion']['texto']);
        $this->assertStringContainsString('declara tener facultades suficientes para obligar a la empresa', $p['autorizacion']['texto']); $this->assertSame('2026-11-21', $p['autorizacion']['version']);
        // Sin tildar, o con un texto viejo, no se activa.
        $this->post('/configuracion/afip/bigsys', ['activo' => true])->assertSessionHasErrors('acepto');
        $this->activar(['version' => '2020-01-01'])->assertSessionHasErrors('acepto');
        $this->assertFalse($this->empresa->fresh()->arca_bigsys); $this->assertSame(0, ArcaAutorizacion::count());
        // Otro usuario con permiso de configuración no puede aceptarla: es del dueño.
        $admin = $this->usuarioConRol('administrador', 'admin@test.com'); $this->actingAs($admin); Auth::login($admin);
        $this->assertFalse($this->get('/configuracion/puntos-venta')->viewData('page')['props']['arca']['puede_aceptar']);
        $this->activar()->assertSessionHasErrors(['acepto' => 'La autorización a BigSys la acepta el dueño de la cuenta: pedile que entre y la active.']);
        $this->assertSame(0, ArcaAutorizacion::count());
        // El dueño la acepta.
        $this->actingAs($this->dueno); Auth::login($this->dueno);
        $this->activar()->assertSessionHas('success');
        $a = ArcaAutorizacion::firstOrFail();
        $this->assertSame($this->dueno->id, $a->user_id); $this->assertStringContainsString('<test@test.com>', $a->usuario);
        $this->assertSame('30-70012345-2', $a->cuit); $this->assertSame('Corralón Test S.R.L.', $a->razon_social); $this->assertSame('BIGSYS S.R.L. (CUIT 30-71234567-1)', $a->representante);
        $this->assertSame(ArcaAutorizaciones::VERSION, $a->version); $this->assertSame($p['autorizacion']['texto'], $a->texto, 'Se guarda el texto exacto que se mostró');
        $this->assertSame('127.0.0.1', $a->ip); $this->assertSame('Navegador de prueba', $a->navegador); $this->assertNotNull($a->aceptada_en); $this->assertNull($a->revocada_en);
        $this->assertTrue($this->empresa->fresh()->arca_bigsys);
        $this->assertTrue(AuditLog::where('descripcion', 'like', '%Autorizó a BigSys a emitir%')->exists());
        $u = $this->get('/configuracion/puntos-venta')->viewData('page')['props']['arca']['autorizacion']['ultima'];
        $this->assertStringContainsString('Dueño Empresa Test', $u['usuario']); $this->assertNull($u['revocada']);
    }

    public function test_apagar_marca_la_constancia_revocada_sin_borrarla_y_explica_como_revocar_en_arca(): void
    {
        $this->activar()->assertSessionHas('success');
        // El admin sí puede apagarla.
        $admin = $this->usuarioConRol('administrador', 'admin@test.com'); $this->actingAs($admin); Auth::login($admin);
        $this->post('/configuracion/afip/bigsys', ['activo' => false])->assertSessionHas('success')->assertSessionHas('arca_revocar', fn($p) => str_contains(implode(' ', $p), 'Administrador de Relaciones') && str_contains(implode(' ', $p), 'BIGSYS S.R.L. (CUIT 30-71234567-1)'));
        $this->assertFalse($this->empresa->fresh()->arca_bigsys);
        $a = ArcaAutorizacion::firstOrFail();
        $this->assertNotNull($a->revocada_en); $this->assertStringContainsString('admin@test.com', $a->revocada_por); $this->assertStringContainsString('Apagada desde Configuración', $a->motivo_revocacion);
        // Reactivarla deja otra constancia; la vieja sigue ahí.
        $this->actingAs($this->dueno); Auth::login($this->dueno);
        $this->activar()->assertSessionHas('success');
        $this->assertSame(2, ArcaAutorizacion::count()); $this->assertSame(1, ArcaAutorizacion::whereNull('revocada_en')->count());
    }

    public function test_al_dar_de_baja_la_empresa_se_apaga_y_al_duenio_le_llegan_los_pasos_para_revocar_en_arca(): void
    {
        config(['mail.default' => 'array']);
        $this->activar();
        $loc = \App\Models\BusinessLocation::create(['business_id' => $this->empresa->id, 'name' => 'Sucursal Sur', 'cuit' => '20-33344455-1', 'razon_social' => 'Sur SRL', 'is_active' => true]);
        $this->activar(['location_id' => $loc->id])->assertSessionHas('success');
        $this->assertTrue($loc->fresh()->arca_bigsys);
        $this->assertSame('Sur SRL', ArcaAutorizacion::where('sucursal_id', $loc->id)->value('razon_social'));
        $this->post('/suscripcion/baja', ['motivo' => 'Cierro el local', 'confirmacion' => 'Empresa Test'])->assertRedirect('/login');
        $b = \App\Models\Business::withTrashed()->find($this->empresa->id);
        $this->assertFalse($b->arca_bigsys); $this->assertFalse($loc->fresh()->arca_bigsys);
        $this->assertSame(0, ArcaAutorizacion::withoutGlobalScopes()->whereNull('revocada_en')->count());
        $this->assertStringContainsString('Baja de la empresa: Baja pedida por el dueño', ArcaAutorizacion::withoutGlobalScopes()->first()->motivo_revocacion);
        $this->assertSame(2, ArcaAutorizacion::withoutGlobalScopes()->count(), 'Las constancias se conservan');
        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn($m) => $m->getOriginalMessage());
        $aviso = $mails->first(fn($m) => str_contains($m->getSubject(), 'revocá la autorización de facturación en ARCA'));
        $this->assertNotNull($aviso, 'Al dueño le llega el mail con los pasos');
        $this->assertSame('test@test.com', $aviso->getTo()[0]->getAddress());
        $this->assertStringContainsString('Administrador de Relaciones de Clave Fiscal', $aviso->getTextBody()); $this->assertStringContainsString('20-33344455-1', $aviso->getTextBody());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('descripcion', 'like', '%se avisó cómo revocar la relación en ARCA (30-70012345-2, 20-33344455-1)%')->exists());
        $texto = ArcaAutorizaciones::textoRevocar('Empresa Test', ['30-70012345-2']);
        $this->assertStringContainsString('revocá también la autorización en ARCA', $texto); $this->assertStringContainsString('BIGSYS S.R.L. (CUIT 30-71234567-1)', $texto);
    }

    public function test_salud_vigila_el_certificado_de_bigsys(): void
    {
        $control = fn() => collect(app(\App\Services\Monitoreo\SaludService::class)->verificar()['controles'])->firstWhere('key', 'arca_certificado');
        // Vence en un año, en producción, nadie lo usa: OK.
        $this->assertSame('ok', $control()['estado']); $this->assertStringContainsString('Alias bigsyserp', $control()['detalle']);
        // Vence en 20 días: aviso. En 10: crítico. Vencido: crítico.
        $this->travel(345)->days(); $this->assertSame('aviso', $control()['estado']); $this->assertStringContainsString('renovalo en ARCA', $control()['detalle']);
        $this->travel(10)->days(); $this->assertSame('critico', $control()['estado']);
        $this->travel(20)->days(); $this->assertStringContainsString('VENCIDO', $control()['detalle']);
        $this->travelBack();
        // En homologación con empresas usándolo: aviso.
        $this->empresa->forceFill(['arca_bigsys' => true])->save(); \App\Models\SistemaConfig::set('arca_produccion', false);
        $this->assertSame('aviso', $control()['estado']); $this->assertStringContainsString('los CAE no valen fiscalmente', $control()['detalle']);
        // Sin certificado y con empresas que lo usan: crítico.
        ArcaPlataforma::borrar();
        $this->assertSame('critico', $control()['estado']); $this->assertStringContainsString('emiten simulado', $control()['detalle']);
    }

    public function test_las_empresas_dadas_de_baja_hace_mas_de_90_dias_se_avisan_y_se_pueden_eliminar(): void
    {
        $control = fn() => collect(app(\App\Services\Monitoreo\SaludService::class)->verificar()['controles'])->firstWhere('key', 'bajas_vencidas');
        [$otra] = $this->crearEmpresa('Se Fue SA', 'sefue');
        app(\App\Services\Suscripciones\SuscripcionService::class)->darDeBaja($otra, 'Cerró');
        $this->assertSame('ok', $control()['estado']);
        $this->travel(91)->days();
        $this->assertSame('aviso', $control()['estado']); $this->assertStringContainsString('Se Fue SA (baja el', $control()['detalle']);
        // El superadmin la puede eliminar aunque esté dada de baja (antes daba 404).
        $su = \App\Models\User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? \App\Models\User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); Auth::login($su);
        $this->getJson("/admin/empresas/{$otra->id}/eliminar")->assertOk()->assertJson(['empresa' => 'Se Fue SA']);
        $this->delete("/admin/empresas/{$otra->id}", ["nombre" => "Se Fue SA"])->assertRedirect("/admin/empresas");
        $this->assertNull(\App\Models\Business::withTrashed()->find($otra->id));
        $this->assertSame('ok', $control()['estado']);
    }

    public function test_los_terminos_y_la_privacidad_dicen_como_funciona(): void
    {
        $t = $this->get('/terminos')->assertOk()->getContent();
        $this->assertStringContainsString('id="facturacion"', $t); $this->assertStringContainsString('La empresa sigue siendo el emisor', $t);
        $this->assertStringContainsString('no responde por rechazos, demoras o caídas de los servicios de ARCA', $t); $this->assertStringContainsString('20/11/2026', $t);
        $p = $this->get('/privacidad')->assertOk()->getContent();
        $this->assertStringContainsString('artículo 25 de la Ley 25.326', $p); $this->assertStringContainsString('con el certificado de BigSys, si autorizaste a BigSys', $p);
        $this->assertStringNotContainsString('con tu propio certificado.</li>', $p);
        // Encargo de tratamiento, responsabilidad y conservación.
        $this->assertStringContainsString('id="datos"', $t); $this->assertStringContainsString('ni siquiera para conservarlos', $t); $this->assertStringContainsString('después los eliminamos', $t);
        $this->assertStringContainsString('no se aplica si el daño se debe a dolo o culpa grave', $t); $this->assertStringContainsString('BigSys la corrige sin cargo', $t);
        $this->assertStringContainsString('es obligación de cada empresa', $p); $this->assertStringNotContainsString('(hasta 10 años)', $p);
        $this->assertStringContainsString('La constancia de que autorizaste a BigSys', $p);
    }

    // Fase 122.1: la empresa de BigSys (mismo CUIT que el certificado) factura lo suyo sin autorizarse a sí misma.
    public function test_la_empresa_del_certificado_activa_sin_autorizar_a_nadie(): void
    {
        $this->empresa->update(['cuit' => '30-71234567-1', 'razon_social' => 'BIGSYS S.R.L.']);
        $this->assertTrue(ArcaPlataforma::esTitular('30712345671')); $this->assertFalse(ArcaPlataforma::esTitular('30700123452')); $this->assertFalse(ArcaPlataforma::esTitular(null));
        $p = $this->get('/configuracion/puntos-venta')->viewData('page')['props']['arca'];
        $this->assertTrue($p['titular']);
        $this->assertStringContainsString('emite sus propios comprobantes electrónicos con su certificado', $p['autorizacion']['texto']);
        $this->assertStringNotContainsString('autoriza a', $p['autorizacion']['texto']);
        // Sin tilde ni versión: igual se activa, porque no hay nada que autorizar. Queda la constancia de quién la activó.
        $this->post('/configuracion/afip/bigsys', ['activo' => true])->assertSessionHas('success', fn($m) => str_contains($m, 'con el certificado de BigSys'));
        $this->assertTrue($this->empresa->fresh()->arca_bigsys);
        $this->assertSame($this->dueno->id, ArcaAutorizacion::firstOrFail()->user_id);
        // Una empresa cliente sigue necesitando aceptar.
        $this->empresa->update(['cuit' => '30-70012345-2', 'arca_bigsys' => false]); $this->dueno->unsetRelation('business');
        $this->assertFalse($this->get('/configuracion/puntos-venta')->viewData('page')['props']['arca']['titular']);
        $this->post('/configuracion/afip/bigsys', ['activo' => true])->assertSessionHasErrors('acepto');
    }
}
