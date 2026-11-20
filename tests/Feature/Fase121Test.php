<?php

namespace Tests\Feature;

use App\Models\ArcaTicket;
use App\Models\Comprobante;
use App\Models\PuntoVenta;
use App\Models\SistemaConfig;
use App\Services\Afip\ArcaDirecto;
use App\Services\Afip\ArcaPlataforma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 121: facturar como en el CRM. Un certificado de BigSys, las empresas delegan el servicio en ARCA y el ERP habla
// directo con ARCA (WSAA + WSFEv1). ARCA se simula con respuestas SOAP; la firma del pedido de acceso es real (openssl).
class Fase121Test extends ErpTestCase
{
    private const CUIT_BIGSYS = '30712345671';
    private array $pedidos = [];      // [servicio, método, cuerpo]
    private array $ptos = [1, 3];      // lo que ARCA tiene dado de alta para web services
    private bool $delegada = true;    // la empresa delegó en BigSys
    private int $ultimo = 41;
    private ?string $obsRechazo = null;
    private bool $taDuplicado = false;
    private int $tokenMalo = 0;       // cuántas veces más ARCA va a decir «No valido token»

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa->update(['cuit' => '30-70012345-2']);
        Http::fake(fn($req) => $this->arca($req));
    }

    // ARCA de mentira: contesta según el método SOAP.
    private function arca($req)
    {
        $body = $req->body();
        if (str_contains($req->url(), 'LoginCms')) {
            $this->pedidos[] = ['wsaa', 'loginCms', $body];
            if ($this->taDuplicado) return Http::response('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>ns1:coe.alreadyAuthenticated</faultcode><faultstring>El CEE ya posee un TA valido para el acceso al WSN solicitado</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>', 500);
            $ta = htmlspecialchars('<?xml version="1.0"?><loginTicketResponse><header><expirationTime>' . now()->addHours(12)->toIso8601String() . '</expirationTime></header><credentials><token>TOKEN-' . count($this->pedidos) . '</token><sign>SIGN</sign></credentials></loginTicketResponse>');
            return Http::response("<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\"><soapenv:Body><loginCmsResponse><loginCmsReturn>{$ta}</loginCmsReturn></loginCmsResponse></soapenv:Body></soapenv:Envelope>");
        }
        preg_match('/<ar:(\w+)>/', substr($body, strpos($body, '<soapenv:Body>')), $m); $metodo = $m[1];
        $this->pedidos[] = ['wsfe', $metodo, $body];
        $envolver = fn($x) => Http::response("<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\"><soap:Body><{$metodo}Response xmlns=\"http://ar.gov.afip.dif.FEV1/\"><{$metodo}Result>{$x}</{$metodo}Result></{$metodo}Response></soap:Body></soap:Envelope>");
        if ($metodo === 'FEDummy') return $envolver('<AppServer>OK</AppServer><DbServer>OK</DbServer><AuthServer>OK</AuthServer>');
        if ($this->tokenMalo > 0) { $this->tokenMalo--; return $envolver('<Errors><Err><Code>600</Code><Msg>ValidacionDeToken: No valido token. Excepcion: Root element is missing.</Msg></Err></Errors>'); }
        if (! $this->delegada) return $envolver('<Errors><Err><Code>600</Code><Msg>ValidacionDeToken: No aparecio CUIT en lista de relaciones: 30700123452</Msg></Err></Errors>');
        return match ($metodo) {
            'FEParamGetPtosVenta' => $this->ptos ? $envolver('<ResultGet>' . implode('', array_map(fn($n) => "<PtoVenta><Nro>{$n}</Nro><EmisionTipo>CAE</EmisionTipo><Bloqueado>N</Bloqueado><FchBaja>NULL</FchBaja></PtoVenta>", $this->ptos)) . '<PtoVenta><Nro>9</Nro><EmisionTipo>CAE</EmisionTipo><Bloqueado>S</Bloqueado><FchBaja>NULL</FchBaja></PtoVenta></ResultGet>') : $envolver('<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'),
            'FECompUltimoAutorizado' => $envolver("<PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><CbteNro>{$this->ultimo}</CbteNro>"),
            'FECAESolicitar' => $this->obsRechazo
                ? $envolver('<FeCabResp><Resultado>R</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><Observaciones><Obs><Code>' . explode('|', $this->obsRechazo)[0] . '</Code><Msg>' . explode('|', $this->obsRechazo)[1] . '</Msg></Obs></Observaciones><Resultado>R</Resultado><CAE></CAE></FECAEDetResponse></FeDetResp>')
                : $envolver('<FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><Concepto>1</Concepto><CbteDesde>' . ($this->ultimo + 1) . '</CbteDesde><Resultado>A</Resultado><CAE>76543210987654</CAE><CAEFchVto>' . now()->addDays(10)->format('Ymd') . '</CAEFchVto></FECAEDetResponse></FeDetResp>'),
            'FECompConsultar' => $envolver('<ResultGet><Concepto>1</Concepto><CbteDesde>42</CbteDesde><CbteFch>' . today()->format('Ymd') . '</CbteFch><ImpTotal>121</ImpTotal><Iva><AlicIva><Id>5</Id><BaseImp>100</BaseImp><Importe>21</Importe></AlicIva></Iva><Resultado>A</Resultado><CodAutorizacion>11112222333344</CodAutorizacion><FchVto>' . now()->addDays(10)->format('Ymd') . '</FchVto></ResultGet>'),
            default => $envolver(''),
        };
    }

    // Un certificado de prueba con su clave, como los de ARCA (serialNumber «CUIT …», CN = alias).
    private function certificado(string $cuit = self::CUIT_BIGSYS, string $alias = 'bigsyserp', $clave = null, int $dias = 365): array
    {
        $clave ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'AR', 'organizationName' => 'BIGSYS S.R.L.', 'commonName' => $alias, 'serialNumber' => "CUIT {$cuit}"], $clave, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $clave, $dias, ['digest_alg' => 'sha256']), $cert);
        openssl_pkey_export($clave, $key);
        return [$cert, $key];
    }

    private function plataforma(bool $produccion = true): void
    {
        [$c, $k] = $this->certificado();
        ArcaPlataforma::cargar($c, $k, $produccion);
    }

    private function delMetodo(string $m): array { return array_values(array_filter($this->pedidos, fn($p) => $p[1] === $m)); }

    private function superadmin(): void
    {
        $su = \App\Models\User::withoutGlobalScopes()->where('is_superadmin', true)->first() ?? \App\Models\User::factory()->create(['is_superadmin' => true, 'business_id' => $this->empresa->id]);
        $this->actingAs($su); Auth::login($su);
    }

    public function test_el_panel_genera_el_pedido_carga_el_certificado_y_la_clave_nunca_sale(): void
    {
        $this->superadmin();
        $this->post('/admin/sistema/arca/pedido', ['cuit' => '30-71234567-1', 'razon' => 'BIGSYS S.R.L.', 'alias' => 'bigsys erp'])->assertSessionHasErrors('alias');
        $this->post('/admin/sistema/arca/pedido', ['cuit' => '30-71234567-1', 'razon' => 'BIGSYS S.R.L.', 'alias' => 'bigsyserp'])->assertSessionHas('success');
        $csr = $this->get('/admin/sistema/arca/pedido.csr')->assertOk()->getContent();
        $sujeto = openssl_csr_get_subject($csr);
        $this->assertSame('bigsyserp', $sujeto['CN']); $this->assertSame('CUIT 30712345671', $sujeto['serialNumber']); $this->assertSame('AR', $sujeto['C']);
        // ARCA firma el pedido y devuelve el .crt; se sube SIN clave (la clave quedó en el servidor).
        $clave = openssl_pkey_get_private(SistemaConfig::get('arca_pedido_key'));
        openssl_x509_export(openssl_csr_sign($csr, null, $clave, 730, ['digest_alg' => 'sha256']), $crt);
        $this->post('/admin/sistema/arca/certificado', ['cert' => UploadedFile::fake()->createWithContent('bigsyserp.crt', $crt), 'produccion' => '1'])->assertSessionHas('success');
        $this->assertTrue(ArcaPlataforma::configurada()); $this->assertNull(SistemaConfig::get('arca_pedido_key'), 'La clave del pedido pasa a ser la del certificado');
        $props = $this->get('/admin/sistema')->viewData('page')['props'];
        $a = $props['arca'];
        $this->assertTrue($a['lista']); $this->assertSame('bigsyserp', $a['alias']); $this->assertSame('30-71234567-1', $a['cuit']); $this->assertTrue($a['produccion']); $this->assertSame(now()->addDays(730)->format('d/m/Y'), $a['vence']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($props));
        $this->assertSame('30-71234567-1', ArcaPlataforma::representante()['cuit']);
        // Un certificado que no es de esa clave no entra; uno con su clave sí (el caso «el mismo del CRM»).
        [$otro, $otraClave] = $this->certificado(alias: 'otro');
        $this->post('/admin/sistema/arca/certificado', ['cert' => UploadedFile::fake()->createWithContent('otro.crt', $otro)])->assertSessionHasErrors('cert');
        $this->post('/admin/sistema/arca/certificado', ['cert' => UploadedFile::fake()->createWithContent('otro.crt', $otro), 'key' => UploadedFile::fake()->createWithContent('otro.key', $otraClave)])->assertSessionHas('success');
        $this->assertSame('otro', ArcaPlataforma::estado()['alias']);
        // Probar: servidores + ticket de acceso firmado de verdad.
        $this->post('/admin/sistema/arca/probar')->assertSessionHas('prueba', fn($p) => $p['ok'] && str_contains($p['texto'], 'ARCA lo aceptó'));
        $in0 = preg_match('/<wsaa:in0>([^<]+)</', $this->delMetodo('loginCms')[0][2], $m) ? base64_decode($m[1]) : '';
        $this->assertSame("\x30", $in0[0], 'El pedido de acceso va firmado en CMS (DER)');
        $this->assertStringContainsString('<service>wsfe</service>', $in0);
        $this->post('/admin/sistema/arca/ambiente', ['produccion' => false]);
        $this->assertFalse(ArcaPlataforma::config()['produccion']);
    }

    public function test_el_ticket_de_acceso_se_pide_una_vez_se_guarda_cifrado_y_avisa_si_otro_sistema_lo_tiene(): void
    {
        $this->plataforma();
        $c = ArcaPlataforma::cliente();
        $this->assertSame(41, $c->ultimoAutorizado('30700123452', 1, 1));
        $this->assertSame(41, $c->ultimoAutorizado('20333444551', 1, 6));
        $this->assertCount(1, $this->delMetodo('loginCms'), 'Un solo ticket para todas las empresas');
        $t = ArcaTicket::firstOrFail();
        $this->assertSame('wsfe', $t->servicio); $this->assertTrue($t->produccion); $this->assertStringNotContainsString('TOKEN', $t->getRawOriginal('token'));
        // Cada llamada viaja con el CUIT de la empresa representada, firmada con el ticket de BigSys.
        $this->assertStringContainsString('<ar:Cuit>30700123452</ar:Cuit>', $this->delMetodo('FECompUltimoAutorizado')[0][2]);
        $this->assertStringContainsString('<ar:Cuit>20333444551</ar:Cuit>', $this->delMetodo('FECompUltimoAutorizado')[1][2]);
        $this->assertStringContainsString('<ar:Token>TOKEN-1</ar:Token>', $this->delMetodo('FECompUltimoAutorizado')[1][2]);
        // Un ticket vencido se renueva; si ARCA dice que ya hay uno vigente (otro sistema con el mismo certificado), se explica.
        $t->update(['expira' => now()->subMinute()]); $this->taDuplicado = true;
        try { $c->ultimoAutorizado('30700123452', 1, 1); $this->fail('Tenía que avisar'); }
        catch (\App\Services\Afip\ArcaError $e) { $this->assertStringContainsString('OTRO sistema usa el mismo certificado', $e->getMessage()); $this->assertStringContainsString('propio certificado', $e->getMessage()); }
    }

    public function test_un_ticket_que_arca_no_acepta_se_descarta_y_se_pide_otro_sin_confundirlo_con_la_delegacion(): void
    {
        $this->plataforma();
        $c = ArcaPlataforma::cliente();
        $c->ultimoAutorizado('30700123452', 1, 1);
        $this->assertCount(1, $this->delMetodo('loginCms'));
        // ARCA invalida el ticket guardado: se descarta, se pide otro y la consulta sale igual.
        $this->tokenMalo = 1;
        $this->assertSame(41, $c->ultimoAutorizado('30700123452', 1, 1));
        $this->assertCount(2, $this->delMetodo('loginCms'));
        // Si ni con un ticket nuevo lo acepta, se dice que es el ticket (no que falte la delegación).
        $this->tokenMalo = 2;
        try { $c->ultimoAutorizado('30700123452', 1, 1); $this->fail('Tenía que avisar'); }
        catch (\App\Services\Afip\ArcaError $e) { $this->assertSame('token', $e->codigo); $this->assertStringContainsString('no aceptó el ticket de acceso', $e->getMessage()); $this->assertStringNotContainsString('autorizó a BigSys', $e->getMessage()); }
        $this->tokenMalo = 2;
        $this->postJson('/configuracion/afip/verificar')->assertOk()->assertJson(['delegacion' => 'error']);
    }

    public function test_la_empresa_verifica_sus_tramites_activa_y_trae_los_puntos_de_venta(): void
    {
        // Sin certificado de BigSys, no se puede.
        $this->postJson('/configuracion/afip/verificar')->assertStatus(422)->assertJsonFragment(['message' => 'BigSys todavía no cargó su certificado para facturar. Avisanos y lo activamos.']);
        $this->plataforma();
        $p = $this->get('/configuracion/puntos-venta')->viewData('page')['props']['arca'];
        $this->assertTrue($p['plataforma']); $this->assertFalse($p['bigsys']); $this->assertSame('30-71234567-1', $p['representante']['cuit']); $this->assertTrue($p['cuit_ok']);
        // 600: falta la delegación. No se puede activar.
        $this->delegada = false;
        $this->postJson('/configuracion/afip/verificar')->assertOk()->assertJson(['delegacion' => 'falta']);
        $this->post('/configuracion/afip/bigsys', ['activo' => true, 'acepto' => true, 'version' => \App\Services\Afip\ArcaAutorizaciones::VERSION])->assertSessionHas('error', fn($m) => str_contains($m, 'todavía no autorizó a BigSys'));
        $this->assertFalse($this->empresa->fresh()->arca_bigsys);
        // Delegó, pero no dio de alta ningún punto de venta para web services.
        $this->delegada = true; $this->ptos = [];
        $this->postJson('/configuracion/afip/verificar')->assertOk()->assertJson(['delegacion' => 'ok', 'alta_punto_venta' => 'falta']);
        // Todo bien: el 0001 coincide, el 0003 está en ARCA y acá no, el 0009 está bloqueado y no cuenta.
        $this->ptos = [1, 3];
        $r = $this->postJson('/configuracion/afip/verificar')->assertOk()->json();
        $this->assertSame(['delegacion' => 'ok', 'alta_punto_venta' => 'ok', 'puntos' => [1, 3], 'faltan_en_arca' => [], 'faltan_aca' => [3]], $r);
        $this->post('/configuracion/afip/traer-puntos', ['numeros' => [3]])->assertSessionHas('success');
        $this->assertSame('electronico', PuntoVenta::where('numero', 3)->value('modo'));
        $this->post('/configuracion/afip/bigsys', ['activo' => true, 'acepto' => true, 'version' => \App\Services\Afip\ArcaAutorizaciones::VERSION])->assertSessionHas('success');
        $b = $this->empresa->fresh(); $this->assertTrue($b->arca_bigsys); $this->assertNotNull($b->arca_verificada_en);
        $this->assertSame('bigsys', app(\App\Services\Comprobantes\AfipEmisor::class)->modo($b));
        // La consulta va con el CUIT de la empresa.
        $this->assertStringContainsString('<ar:Cuit>30700123452</ar:Cuit>', $this->delMetodo('FEParamGetPtosVenta')[0][2]);
    }

    public function test_emite_con_cae_a_traves_de_bigsys_con_todo_lo_que_pide_arca(): void
    {
        $this->plataforma(); $this->empresa->forceFill(['arca_bigsys' => true])->save(); $this->dueno->unsetRelation('business');
        $p = $this->articulo(['stock_inicial' => 10]);
        $cli = $this->cliente(['condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-70098765-1']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]]);
        $this->assertSame('aprobado', $f->afip_estado); $this->assertSame(42, (int) $f->numero); $this->assertSame('76543210987654', $f->cae);
        $x = $this->delMetodo('FECAESolicitar')[0][2];
        $this->assertStringContainsString('<ar:Cuit>30700123452</ar:Cuit>', $x, 'Factura la empresa, no BigSys');
        $this->assertStringContainsString('<ar:CondicionIVAReceptorId>1</ar:CondicionIVAReceptorId>', $x, 'RG 5616: ahora sí viaja');
        $this->assertStringContainsString('<ar:DocTipo>80</ar:DocTipo><ar:DocNro>30700987651</ar:DocNro>', $x);
        $this->assertStringContainsString('<ar:ImpNeto>200.00</ar:ImpNeto>', $x); $this->assertStringContainsString('<ar:ImpIVA>42.00</ar:ImpIVA>', $x);
        // El orden del WSDL: … MonCotiz, CondicionIVAReceptorId, CbtesAsoc, Tributos, Iva …
        $this->assertLessThan(strpos($x, '<ar:Iva>'), strpos($x, '<ar:CondicionIVAReceptorId>'));
        $this->assertLessThan(strpos($x, '<ar:CondicionIVAReceptorId>'), strpos($x, '<ar:MonCotiz>'));
        $this->assertStringContainsString('<ar:AlicIva><ar:Id>5</ar:Id><ar:BaseImp>200.00</ar:BaseImp><ar:Importe>42.00</ar:Importe></ar:AlicIva>', $x);
        // Nota de crédito: lleva el comprobante asociado, después de la condición del receptor.
        $svc = app(\App\Services\Comprobantes\ComprobanteService::class);
        $nc = $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'origen_id' => $f->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]]));
        $this->assertSame('aprobado', $nc->afip_estado);
        $xn = collect($this->delMetodo('FECAESolicitar'))->last()[2];
        $this->assertStringContainsString('<ar:CbteTipo>3</ar:CbteTipo>', $xn);
        $this->assertStringContainsString('<ar:CbtesAsoc><ar:CbteAsoc><ar:Tipo>1</ar:Tipo><ar:PtoVta>1</ar:PtoVta><ar:Nro>42</ar:Nro><ar:Cuit>30700123452</ar:Cuit>', $xn);
        $this->assertLessThan(strpos($xn, '<ar:CbtesAsoc>'), strpos($xn, '<ar:CondicionIVAReceptorId>'));
        $this->assertLessThan(strpos($xn, '<ar:Iva>'), strpos($xn, '<ar:CbtesAsoc>'));
    }

    public function test_rechazos_duplicado_recuperado_sin_delegacion_y_exportacion(): void
    {
        $this->plataforma(); $this->empresa->forceFill(['arca_bigsys' => true])->save(); $this->dueno->unsetRelation('business');
        $p = $this->articulo(['stock_inicial' => 50]);
        // Rechazo con observación: queda rechazado con la explicación.
        $this->obsRechazo = '10015|El campo CbteFch es invalido';
        try { $this->factura($this->cliente(['cuit' => '30-70098765-1', 'condicion_iva' => 'Responsable Inscripto']), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $this->fail('Tenía que rechazar'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('fecha del comprobante está fuera del rango', $e->getMessage()); $this->assertStringContainsString('(10015)', $e->getMessage()); }
        // Número ya registrado (se cortó después de autorizar): adopta el CAE que tiene ARCA.
        $this->obsRechazo = '10016|El numero de comprobante ya fue registrado';
        $f2 = $this->factura($this->cliente(['cuit' => '30-70098765-1', 'condicion_iva' => 'Responsable Inscripto', 'name' => 'Otro']), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('aprobado', $f2->afip_estado); $this->assertSame('11112222333344', $f2->cae);
        // Si la empresa revoca la delegación: rechazado, y la explicación dice qué hacer.
        $this->obsRechazo = null; $this->delegada = false;
        try { $this->factura($this->cliente(['name' => 'Tercero']), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $this->fail('Tenía que rechazar'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('todavía no autorizó a BigSys a facturar en su nombre', $e->getMessage()); $this->assertStringContainsString('Administrador de Relaciones', $e->getMessage()); }
        // Padrón: con BigSys no está (necesita certificado propio), con un mensaje claro.
        $this->getJson('/clientes/padron/30700987651')->assertStatus(422)->assertJsonFragment(['error' => 'Para consultar el padrón de ARCA hace falta el certificado propio de la empresa (Configuración → Puntos de venta y ARCA). Con la facturación por BigSys, completá los datos a mano.']);
        // Probar conexión desde la empresa.
        $this->delegada = true;
        $this->post('/configuracion/afip/probar')->assertSessionHas('afip_prueba', fn($r) => $r['ok'] && str_contains($r['modo'], 'a través de BigSys'));
    }

    public function test_con_certificado_propio_tambien_va_directo_y_manda_la_condicion_del_receptor(): void
    {
        [$c, $k] = $this->certificado('30700123452', 'empresa');
        $this->empresa->update(['afip_cert_path' => \App\Services\Afip\CertificadoCifrado::guardar($this->empresa, 'cert.crt', $c), 'afip_key_path' => \App\Services\Afip\CertificadoCifrado::guardar($this->empresa, 'private.key', $k), 'afip_produccion' => false]);
        $this->dueno->unsetRelation('business');
        $f = $this->factura(\App\Models\Contact::where('name', 'Consumidor Final')->first(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('aprobado', $f->afip_estado);
        $x = $this->delMetodo('FECAESolicitar')[0][2];
        $this->assertStringContainsString('<ar:Cuit>30700123452</ar:Cuit>', $x); $this->assertStringContainsString('<ar:CondicionIVAReceptorId>5</ar:CondicionIVAReceptorId>', $x);
        $this->assertTrue(collect($this->pedidos)->every(fn($p) => ! str_contains($p[2], 'servicios1.afip')), 'Homologación');
        $this->assertSame(1, ArcaTicket::where('produccion', false)->count());
    }

    public function test_el_comando_carga_el_certificado_desde_archivos_del_servidor(): void
    {
        [$c, $k] = $this->certificado();
        $dir = sys_get_temp_dir() . '/arca-cmd-' . uniqid(); mkdir($dir);
        file_put_contents("{$dir}/c.crt", $c); file_put_contents("{$dir}/k.key", $k);
        $this->artisan('arca:certificado', ['cert' => "{$dir}/c.crt", 'key' => "{$dir}/k.key", '--produccion' => true])->expectsOutputToContain('Certificado cargado: alias bigsyserp')->expectsOutputToContain('ARCA lo aceptó')->assertSuccessful();
        $this->assertTrue(ArcaPlataforma::config()['produccion']);
        $this->artisan('arca:certificado', ['cert' => "{$dir}/no-existe.crt"])->assertFailed();
        array_map('unlink', glob("{$dir}/*")); rmdir($dir);
    }

    // Producción: servicios1.afip.gov.ar negocia con una clave DH vieja que OpenSSL 3 rechaza; sólo ahí se baja el nivel.
    public function test_servicios1_baja_el_nivel_de_openssl_y_el_resto_no(): void
    {
        $this->assertSame(['curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1']], \App\Services\Afip\ArcaDirecto::opcionesConexion(\App\Services\Afip\ArcaDirecto::URLS['wsfe']['prod']));
        $this->assertNotEmpty(\App\Services\Afip\ArcaDirecto::opcionesConexion('https://servicios1.afip.gov.ar/wsfexv1/service.asmx'));
        foreach (['https://wsaa.afip.gov.ar/ws/services/LoginCms', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx', 'https://servicios1.afip.gov.ar.otro.com/x'] as $u) $this->assertSame([], \App\Services\Afip\ArcaDirecto::opcionesConexion($u), $u);
    }
}
