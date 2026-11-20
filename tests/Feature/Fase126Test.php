<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\ArcaCaea;
use App\Models\ArcaTicket;
use App\Models\PuntoVenta;
use App\Services\Afip\ArcaDirecto;
use App\Services\Afip\ArcaError;
use App\Services\Afip\CaeaService;
use App\Services\Afip\QrArca;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 126: contingencia con CAEA. Si ARCA no responde, la factura sale con el CAEA de la quincena y se informa después.
class Fase126Test extends ErpTestCase
{
    private ArcaFalsa $arca;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->arca = new ArcaFalsa();
        app()->instance('afip.fake', $this->arca);
        $this->empresa->update(['afip_cert_path' => 'afip/1/cert.crt.enc', 'afip_key_path' => 'afip/1/private.key.enc', 'cuit' => '30-71234567-8']);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-03 10:00'));
    }

    private function conCaea(): PuntoVenta
    {
        $pv = PuntoVenta::create(['business_id' => $this->empresa->id, 'numero' => 9, 'modo' => 'caea', 'activo' => true]);
        $this->empresa->forceFill(['arca_caea' => true])->save();
        app(CaeaService::class)->pedirProximos();
        return $pv;
    }

    private function arcaCaida(): void { $this->arca->alCrear = fn() => throw new ArcaError('No se pudo conectar con ARCA (WSFE): cURL error 28: Operation timed out'); }

    public function test_pide_el_caea_de_la_quincena_y_el_de_la_proxima_desde_cinco_dias_antes(): void
    {
        $this->conCaea();
        $this->assertSame([[202612, 1]], $this->arca->caeaPedidos, 'El 3/12 sólo el de la quincena en curso');
        $c = ArcaCaea::firstOrFail();
        $this->assertSame('2026-12-01', $c->desde->toDateString()); $this->assertSame('2026-12-15', $c->hasta->toDateString()); $this->assertFalse($c->produccion);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-11 06:40'));
        $this->artisan('arca:caea')->assertSuccessful();
        $this->assertSame([[202612, 1], [202612, 2]], $this->arca->caeaPedidos, 'Desde el 11/12 también el de la segunda quincena, y no se vuelve a pedir el que ya está');
        $this->travelTo(\Carbon\Carbon::parse('2026-12-27 06:40'));
        $this->artisan('arca:caea')->assertSuccessful();
        $this->assertSame([202701, 1], end($this->arca->caeaPedidos), 'A fin de diciembre, el de la primera de enero');
        $e = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($e) => str_contains($e->command ?? '', 'arca:caea'));
        $this->assertSame('40 6 * * *', $e->expression);
    }

    public function test_con_arca_caido_la_factura_sale_con_caea_en_su_punto_de_venta(): void
    {
        $this->conCaea();
        PuntoVenta::create(['business_id' => $this->empresa->id, 'numero' => 3, 'modo' => 'electronico', 'activo' => true]);
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-70012345-6']);
        // Con ARCA andando, el punto de venta CAEA no se usa.
        $f1 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]], ['tipo' => 'FA']);
        $this->assertNotSame(9, (int) $f1->punto_venta); $this->assertNull($f1->cae_tipo);
        $this->arcaCaida();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]], ['tipo' => 'FA']);
        $this->assertSame('aprobado', $f->afip_estado); $this->assertSame('CAEA', $f->cae_tipo);
        $this->assertSame(9, (int) $f->punto_venta); $this->assertSame(1, (int) $f->numero);
        $this->assertSame(ArcaCaea::firstOrFail()->caea, $f->cae); $this->assertSame('2026-12-15', $f->cae_vto->toDateString());
        $this->assertNull($f->caea_informado_en);
        $this->assertSame(now()->format('YmdHis'), $f->afip_respuesta['generado']);
        $this->assertSame(9, $f->afip_respuesta['enviado']['PtoVta']); $this->assertSame(1, $f->afip_respuesta['enviado']['CbteDesde']);
        // El segundo, correlativo; el QR dice «A» (CAEA) y la factura impresa dice CAEA.
        $f2 = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 50]], ['tipo' => 'FA']);
        $this->assertSame(2, (int) $f2->numero);
        $this->assertSame('A', QrArca::datos($f)['tipoCodAut']); $this->assertSame('E', QrArca::datos($f1)['tipoCodAut']);
        $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->assertSee('CAEA: <b>' . $f->cae, false);
        $v = $this->get("/comprobantes/{$f->id}")->viewData('page')['props']['c'];
        $this->assertSame('CAEA', $v['cae_tipo']); $this->assertNull($v['caea_informado']);
        // No se puede anular (es fiscal y válida) y el stock y la cuenta corriente impactaron.
        $this->assertEqualsWithDelta(6, (float) $p->fresh()->stock, 0.001);
        // El punto de venta CAEA no aparece para elegir en un comprobante nuevo.
        $pvs = collect($this->get('/comprobantes/nuevo')->viewData('page')['props']['puntosVenta'])->pluck('numero')->all();
        $this->assertContains(3, $pvs); $this->assertNotContains(9, $pvs);
    }

    public function test_sin_contingencia_activa_queda_pendiente_como_siempre(): void
    {
        PuntoVenta::create(['business_id' => $this->empresa->id, 'numero' => 9, 'modo' => 'caea', 'activo' => true]);
        $this->arcaCaida();
        $f = $this->factura($this->cliente(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('pendiente', $f->afip_estado); $this->assertNull($f->cae_tipo); $this->assertNull($f->numero);
        // Activa pero sin CAEA para la fecha (ARCA no lo dio): también pendiente.
        $this->empresa->forceFill(['arca_caea' => true])->save();
        $f2 = $this->factura($this->cliente(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('pendiente', $f2->afip_estado);
    }

    public function test_cuando_arca_vuelve_se_informa_solo_con_la_fecha_y_hora_de_generacion(): void
    {
        $this->conCaea(); $this->arcaCaida();
        $f = $this->factura($this->cliente(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $gen = $f->afip_respuesta['generado'];
        $this->travel(2)->hours();
        $this->artisan('afip:reintentar')->assertSuccessful();
        $f->refresh();
        $this->assertNotNull($f->caea_informado_en);
        $d = $this->arca->caeaInformados[0];
        $this->assertSame($f->cae, $d['CAEA']); $this->assertSame($gen, $d['CbteFchHsGen']); $this->assertSame(9, $d['PtoVta']); $this->assertSame(1, $d['CbteDesde']);
        // No se informa dos veces.
        $this->artisan('afip:reintentar')->assertSuccessful();
        $this->assertCount(1, $this->arca->caeaInformados);
    }

    public function test_si_arca_rechaza_el_informe_avisa_critico_y_la_salud_lo_marca(): void
    {
        $this->conCaea(); $this->arcaCaida();
        $f = $this->factura($this->cliente(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $s = collect(app(\App\Services\Monitoreo\SaludService::class)->verificar()['controles'])->firstWhere('key', 'caea');
        $this->assertSame('aviso', $s['estado']);
        $this->arca->alInformar = fn() => throw new ArcaError('(10016) El numero o fecha del comprobante no se corresponde con el proximo a autorizar', '10016');
        app(CaeaService::class)->informarPendientes();
        $f->refresh();
        $this->assertNull($f->caea_informado_en); $this->assertStringContainsString('10016', $f->afip_respuesta['error_informe']);
        $a = Alerta::withoutGlobalScopes()->where('tipo', 'caea_rechazado')->firstOrFail(); $this->assertSame('critica', $a->severidad);
        $s = collect(app(\App\Services\Monitoreo\SaludService::class)->verificar()['controles'])->firstWhere('key', 'caea');
        $this->assertSame('critico', $s['estado']);
        // Se corrige y se informa: la alerta se resuelve.
        $this->arca->alInformar = null;
        app(CaeaService::class)->informarPendientes();
        $this->assertNotNull($f->fresh()->caea_informado_en); $this->assertNotNull($a->fresh()->resuelta_en);
    }

    public function test_el_punto_de_venta_que_no_uso_el_caea_se_informa_sin_movimiento(): void
    {
        $this->conCaea();
        $this->travelTo(\Carbon\Carbon::parse('2026-12-16 06:40'));
        $this->artisan('arca:caea')->assertSuccessful();
        $caea = ArcaCaea::where('orden', 1)->firstOrFail();
        $this->assertSame([[9, $caea->caea]], $this->arca->sinMovimiento);
        $this->assertArrayHasKey('9', $caea->fresh()->sin_movimiento);
        $this->artisan('arca:caea')->assertSuccessful();
        $this->assertCount(1, $this->arca->sinMovimiento, 'Una sola vez');
    }

    public function test_el_que_si_uso_el_caea_no_se_informa_sin_movimiento(): void
    {
        $this->conCaea(); $this->arcaCaida();
        $this->factura($this->cliente(), [['product_id' => $this->articulo(['stock_inicial' => 5])->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-16 06:40'));
        $this->artisan('arca:caea')->assertSuccessful();
        $this->assertSame([], $this->arca->sinMovimiento);
    }

    public function test_la_pantalla_activa_la_contingencia_y_pide_el_caea(): void
    {
        $this->post('/configuracion/afip/caea', ['activo' => true])->assertSessionHas('error', fn($m) => str_contains($m, 'Falta el punto de venta para CAEA'));
        $this->assertFalse((bool) $this->empresa->fresh()->arca_caea);
        $this->post('/configuracion/puntos-venta', ['numero' => 9, 'modo' => 'caea', 'activo' => true])->assertSessionHasNoErrors();
        $this->post('/configuracion/afip/caea', ['activo' => true])->assertSessionHas('success', fn($m) => str_contains($m, 'ARCA dio el CAEA'));
        $this->assertTrue((bool) $this->empresa->fresh()->arca_caea);
        $p = $this->get('/configuracion/puntos-venta')->viewData('page')['props']['caea'];
        $this->assertTrue($p['activo']); $this->assertSame(9, $p['pv']); $this->assertSame('1.ª quincena de diciembre 2026', $p['vigente']['quincena']);
        $this->post('/configuracion/afip/caea', ['activo' => false])->assertSessionHas('success');
        $this->assertFalse((bool) $this->empresa->fresh()->arca_caea);
    }

    // Lo que viaja a ARCA de verdad (sin el doble): los cuatro métodos con los nombres y el orden del WSDL.
    public function test_los_sobres_soap_del_caea(): void
    {
        app()->forgetInstance('afip.fake');
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'AR', 'commonName' => 'prueba', 'serialNumber' => 'CUIT 30712345678'], $k, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $k, 30, ['digest_alg' => 'sha256']), $cert); openssl_pkey_export($k, $key);
        $d = new ArcaDirecto($cert, $key, false);
        ArcaTicket::create(['huella' => $d->huella(), 'servicio' => 'wsfe', 'produccion' => false, 'token' => Crypt::encryptString('TOK'), 'sign' => Crypt::encryptString('SIG'), 'expira' => now()->addHours(10)]);
        $enviados = [];
        Http::fake(function ($req) use (&$enviados) {
            $body = $req->body(); preg_match('/<ar:(\w+)>/', substr($body, strpos($body, '<soapenv:Body>')), $m); $metodo = $m[1]; $enviados[$metodo] = $body;
            $x = match ($metodo) {
                'FECAEASolicitar' => '<Errors><Err><Code>15008</Code><Msg>Ya existe un CAEA otorgado para el periodo y orden</Msg></Err></Errors>',
                'FECAEAConsultar' => '<ResultGet><CAEA>31234567890123</CAEA><Periodo>202612</Periodo><Orden>2</Orden><FchVigDesde>20261216</FchVigDesde><FchVigHasta>20261231</FchVigHasta><FchTopeInf>20270108</FchTopeInf><FchProceso>20261211064000</FchProceso></ResultGet>',
                'FECAEARegInformativo' => '<FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEADetResponse><Concepto>1</Concepto><CbteDesde>1</CbteDesde><Resultado>A</Resultado><CAEA>31234567890123</CAEA></FECAEADetResponse></FeDetResp>',
                'FECAEASinMovimientoInformar' => '<CAEA>31234567890123</CAEA><FchProceso>20270101</FchProceso><PtoVta>9</PtoVta><Resultado>A</Resultado>',
            };
            return Http::response("<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\"><soap:Body><{$metodo}Response xmlns=\"http://ar.gov.afip.dif.FEV1/\"><{$metodo}Result>{$x}</{$metodo}Result></{$metodo}Response></soap:Body></soap:Envelope>");
        });
        try { $d->caeaSolicitar('30712345678', 202612, 2); $this->fail('Tenía que avisar que ya existe'); } catch (ArcaError $e) { $this->assertSame('15008', $e->codigo); }
        $this->assertStringContainsString('<ar:Periodo>202612</ar:Periodo><ar:Orden>2</ar:Orden>', $enviados['FECAEASolicitar']);
        $r = $d->caeaConsultar('30712345678', 202612, 2);
        $this->assertSame('31234567890123', $r['CAEA']); $this->assertSame('20270108', $r['FchTopeInf']);
        $i = $d->caeaInformar('30712345678', ['CantReg' => 1, 'PtoVta' => 9, 'CbteTipo' => 1, 'Concepto' => 1, 'DocTipo' => 80, 'DocNro' => 30700123456, 'CbteDesde' => 1, 'CbteHasta' => 1, 'CbteFch' => '20261220', 'ImpTotal' => 121, 'ImpTotConc' => 0, 'ImpNeto' => 100, 'ImpOpEx' => 0, 'ImpIVA' => 21, 'ImpTrib' => 0, 'MonId' => 'PES', 'MonCotiz' => 1, 'CondicionIVAReceptorId' => 1, 'Iva' => [['Id' => 5, 'BaseImp' => 100, 'Importe' => 21]], 'CAEA' => '31234567890123', 'CbteFchHsGen' => '20261220103000']);
        $this->assertSame('A', $i['Resultado']);
        $x = $enviados['FECAEARegInformativo'];
        $this->assertStringContainsString('<ar:FeCAEARegInfReq><ar:FeCabReq><ar:CantReg>1</ar:CantReg><ar:PtoVta>9</ar:PtoVta><ar:CbteTipo>1</ar:CbteTipo></ar:FeCabReq>', $x);
        $this->assertMatchesRegularExpression('#<ar:FECAEADetRequest><ar:Concepto>1</ar:Concepto>.*<ar:Iva><ar:AlicIva><ar:Id>5</ar:Id>.*</ar:Iva>.*<ar:CAEA>31234567890123</ar:CAEA><ar:CbteFchHsGen>20261220103000</ar:CbteFchHsGen></ar:FECAEADetRequest>#', $x);
        $this->assertStringContainsString('<ar:Auth><ar:Token>TOK</ar:Token><ar:Sign>SIG</ar:Sign><ar:Cuit>30712345678</ar:Cuit></ar:Auth>', $x);
        $s = $d->caeaSinMovimiento('30712345678', 9, '31234567890123');
        $this->assertSame('A', $s['Resultado']);
        $this->assertStringContainsString('<ar:PtoVta>9</ar:PtoVta><ar:CAEA>31234567890123</ar:CAEA>', $enviados['FECAEASinMovimientoInformar']);
    }
}
