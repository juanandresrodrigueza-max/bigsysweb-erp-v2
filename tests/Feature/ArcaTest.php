<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Services\Afip\AfipErrores;
use App\Services\Afip\QrArca;
use App\Services\Comprobantes\AfipEmisor;
use App\Services\Comprobantes\ComprobanteService;
use App\Support\Cuit;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Doble de ARCA: se programa qué responde cada llamada.
class ArcaFalsa
{
    public array $enviados = [];
    public int $ultimo = 41;
    public ?\Closure $alCrear = null;
    public ?array $info = null;

    public function getLastVoucher(int $pv, int $tipo): int { return $this->ultimo; }
    public function createVoucher(array $data): array { $this->enviados[] = $data; if ($this->alCrear) return ($this->alCrear)($data); return ['CAE' => '71234567890123', 'CAEFchVto' => now()->addDays(10)->format('Ymd')]; }
    public function getVoucherInfo(int $numero, int $pv, int $tipo): ?array { return $this->info; }
    public function getServerStatus(): array { return ['AppServer' => 'OK', 'DbServer' => 'OK', 'AuthServer' => 'OK']; }
    public function padron(string $cuit): ?array { return ['cuit' => Cuit::formatear($cuit), 'nombre' => 'ACME SRL', 'condicion_iva' => 'Responsable Inscripto', 'direccion' => 'Av. Colón 1234', 'localidad' => 'Córdoba', 'provincia' => 'Córdoba', 'codigo_postal' => '5000', 'estado' => 'ACTIVO']; }
}

class ArcaTest extends ErpTestCase
{
    private ArcaFalsa $arca;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->arca = new ArcaFalsa();
        app()->instance('afip.fake', $this->arca);
        $this->empresa->update(['afip_cert_path' => 'afip/1/cert.crt.enc', 'afip_key_path' => 'afip/1/private.key.enc', 'cuit' => '30-71234567-8']);
    }

    public function test_pide_el_cae_con_todo_lo_que_exige_arca_hoy(): void
    {
        $p = $this->articulo(['stock_inicial' => 10, 'iva' => 21]);
        $s = $this->articulo(['name' => 'Servicio técnico', 'tipo' => 'servicio', 'controla_stock' => false, 'iva' => 10.5]);
        $cli = $this->cliente(['condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-70012345-6', 'percepcion_iibb' => true, 'alicuota_percepcion_iibb' => 3]);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100], ['product_id' => $s->id, 'cantidad' => 1, 'precio_unit' => 50]]);
        $this->assertSame('aprobado', $f->afip_estado); $this->assertSame(42, (int) $f->numero); $this->assertSame('71234567890123', $f->cae);
        $d = $this->arca->enviados[0];
        $this->assertSame(1, $d['CondicionIVAReceptorId'], 'RG 5616: condición IVA del receptor');
        $this->assertSame(3, $d['Concepto'], 'Productos y servicios');
        $this->assertArrayHasKey('FchServDesde', $d); $this->assertArrayHasKey('FchVtoPago', $d);
        $this->assertCount(2, $d['Iva']); $this->assertSame([5, 4], array_column($d['Iva'], 'Id'));
        $this->assertEqualsWithDelta(250, $d['ImpNeto'], 0.01); $this->assertEqualsWithDelta(47.25, $d['ImpIVA'], 0.01);
        $this->assertEqualsWithDelta($d['ImpNeto'] + $d['ImpOpEx'] + $d['ImpIVA'] + $d['ImpTrib'], $d['ImpTotal'], 0.001, 'Los importes cierran');
        $this->assertEqualsWithDelta((float) $f->total, $d['ImpTotal'], 0.01);
        if ($d['ImpTrib'] > 0) { $this->assertSame(7, $d['Tributos'][0]['Id'], 'Percepción IIBB como tributo 7'); }
    }

    public function test_consumidor_final_y_monotributista_van_con_su_condicion_y_sin_iva_discriminado(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]);
        $cf = \App\Models\Contact::where('name', 'Consumidor Final')->first();
        $f = $this->factura($cf, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('FB', $f->tipo); $this->assertSame(5, $this->arca->enviados[0]['CondicionIVAReceptorId']); $this->assertSame(99, $this->arca->enviados[0]['DocTipo']);
        // Empresa monotributista: factura C sin IVA discriminado.
        $this->empresa->update(['condicion_iva' => 'Monotributista']); $this->dueno->load('business');
        $f2 = $this->factura($this->cliente(['cuit' => '30-70012345-6']), [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('FC', $f2->tipo);
        $d = $this->arca->enviados[1];
        $this->assertSame(11, $d['CbteTipo']); $this->assertArrayNotHasKey('Iva', $d); $this->assertSame(0.0, (float) $d['ImpIVA']); $this->assertEqualsWithDelta((float) $f2->total, $d['ImpTotal'], 0.01);
    }

    public function test_nota_de_credito_lleva_el_comprobante_asociado_o_el_periodo(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente();
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $svc = app(ComprobanteService::class);
        $nc = $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'origen_id' => $f->id, 'items' => [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]]));
        $d = end($this->arca->enviados);
        $this->assertSame(3, $d['CbteTipo']); $this->assertSame(1, $d['CbtesAsoc'][0]['Tipo']); $this->assertSame((int) $f->numero, $d['CbtesAsoc'][0]['Nro']); $this->assertArrayNotHasKey('PeriodoAsoc', $d);
        $nc2 = $svc->emitir($svc->guardarBorrador(['contact_id' => $cli->id, 'tipo' => 'NCX', 'fecha' => today()->toDateString(), 'condicion' => 'cta_cte', 'items' => [['descripcion' => 'Ajuste', 'cantidad' => 1, 'precio_unit' => 10, 'alicuota_iva' => 21]]]));
        $d = end($this->arca->enviados);
        $this->assertArrayHasKey('PeriodoAsoc', $d, 'Sin factura asociada ARCA exige el período');
    }

    public function test_contingencia_sin_conexion_el_comprobante_queda_pendiente_y_se_autoriza_al_reintentar(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente();
        $this->arca->alCrear = fn() => throw new \RuntimeException('SoapFault: Could not connect to host wsaahomo.afip.gov.ar');
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 2, 'precio_unit' => 100]]);
        $this->assertSame('emitido', $f->estado); $this->assertSame('pendiente', $f->afip_estado); $this->assertNull($f->numero); $this->assertNull($f->cae);
        $this->assertEqualsWithDelta(242, (float) $cli->fresh()->balance, 0.01, 'La cuenta corriente impacta igual');
        $this->assertEqualsWithDelta(8, (float) $p->fresh()->stock, 0.001, 'El stock sale igual');
        $this->assertStringContainsString('No se pudo conectar', $f->afip_respuesta['explicacion']['que']);
        // Sigue caído: sigue pendiente.
        $r = app(ComprobanteService::class)->reintentarCae($f);
        $this->assertSame('pendiente', $r['estado']); $this->assertSame(2, $f->fresh()->afip_respuesta['intentos']);
        // Vuelve ARCA: el comando lo autoriza y recién ahí recibe número y CAE.
        $this->arca->alCrear = null;
        $this->artisan('afip:reintentar')->assertExitCode(0);
        $f->refresh();
        $this->assertSame('aprobado', $f->afip_estado); $this->assertSame(42, (int) $f->numero); $this->assertSame('71234567890123', $f->cae);
        $this->assertSame('0001-00000042', $f->numeroFormateado());
        $this->assertStringContainsString('0001-00000042', \App\Models\CuentaCorriente::where('comprobante_id', $f->id)->value('concepto'));
        $this->assertTrue(AuditLog::where('accion', 'emitir')->where('descripcion', 'like', '%estaba pendiente%')->exists());
        $this->assertNotNull(QrArca::url($f));
    }

    public function test_si_arca_dice_que_el_numero_ya_existe_se_recupera_el_cae(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente();
        $this->arca->alCrear = fn() => throw new \RuntimeException('(10016) El numero o fecha del comprobante no se corresponde con el proximo a autorizar. Consultar metodo FECompUltimoAutorizado.');
        $this->arca->info = ['CodAutorizacion' => '79999999999999', 'FchVto' => now()->addDays(9)->format('Ymd'), 'ImpTotal' => 121.0, 'Resultado' => 'A'];
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $this->assertSame('aprobado', $f->afip_estado); $this->assertSame('79999999999999', $f->cae); $this->assertSame(42, (int) $f->numero);
        // Si el total no coincide, no se adopta: es un rechazo con explicación.
        $this->arca->info['ImpTotal'] = 500;
        try { $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]); $this->fail('Debía rechazar'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('ya existe en ARCA', $e->errors()['afip'][0]); }
    }

    public function test_qr_de_arca_errores_explicados_cuit_y_verificacion(): void
    {
        $p = $this->articulo(['stock_inicial' => 10]); $cli = $this->cliente(['cuit' => '30-70012345-6']);
        $f = $this->factura($cli, [['product_id' => $p->id, 'cantidad' => 1, 'precio_unit' => 100]]);
        $url = QrArca::url($f);
        $this->assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $url);
        $d = json_decode(base64_decode(substr($url, strlen('https://www.afip.gob.ar/fe/qr/?p='))), true);
        $this->assertEquals(['ver' => 1, 'fecha' => today()->format('Y-m-d'), 'cuit' => 30712345678, 'ptoVta' => 1, 'tipoCmp' => 1, 'nroCmp' => 42, 'importe' => 121, 'moneda' => 'PES', 'ctz' => 1, 'tipoDocRec' => 80, 'nroDocRec' => 30700123456, 'tipoCodAut' => 'E', 'codAut' => 71234567890123], $d);
        $this->assertStringStartsWith('data:image/png;base64,', QrArca::imagen($f));
        $this->get("/comprobantes/{$f->id}/imprimir")->assertOk()->assertSee('Comprobante Autorizado')->assertSee('data:image/png;base64', false);
        // Errores típicos traducidos.
        $this->assertSame('fecha', AfipErrores::explicar('(10015) Campo CbteFch: la fecha es anterior a...')['tipo']);
        $this->assertSame('certificado', AfipErrores::explicar('ns1:cms.cert.expired: Certificado expirado')['tipo']);
        $this->assertTrue(AfipErrores::esConexion('SoapFault: Could not connect to host'));
        $this->assertFalse(AfipErrores::esConexion('(10048) DocNro inválido'));
        // CUIT: dígito verificador.
        $this->assertTrue(Cuit::valido('30-71234567-1')); $this->assertTrue(Cuit::valido('20-12345678-6')); $this->assertFalse(Cuit::valido('30-71234567-9')); $this->assertFalse(Cuit::valido('123'));
        $this->assertSame('30-71234567-8', Cuit::formatear('30712345678'));
        $this->post('/clientes', ['name' => 'Malo', 'condicion_iva' => 'Responsable Inscripto', 'cuit' => '30-71234567-9'])->assertSessionHasErrors('cuit');
        // Verificar contra ARCA y padrón.
        $this->arca->info = ['CodAutorizacion' => '71234567890123', 'ImpTotal' => 121.0, 'CbteFch' => today()->format('Ymd'), 'Resultado' => 'A'];
        $this->getJson("/comprobantes/{$f->id}/verificar-arca")->assertOk()->assertJsonPath('ok', true);
        $this->arca->info['ImpTotal'] = 999;
        $this->getJson("/comprobantes/{$f->id}/verificar-arca")->assertOk()->assertJsonPath('ok', false);
        $this->getJson('/clientes/padron/30712345671')->assertOk()->assertJsonPath('nombre', 'ACME SRL')->assertJsonPath('condicion_iva', 'Responsable Inscripto');
        $this->getJson('/clientes/padron/30712345679')->assertStatus(422);
        $this->post('/configuracion/afip/probar')->assertSessionHas('afip_prueba', fn($r) => $r['ok'] === true && count($r['pasos']) >= 3);
    }
}
