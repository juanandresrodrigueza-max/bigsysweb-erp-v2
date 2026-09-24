<?php

namespace Tests\Feature;

use App\Services\Afip\Wsfex;
use Illuminate\Support\Facades\Http;
use Tests\ErpTestCase;

// El cliente WSFEX arma el SOAP a mano: se prueba contra respuestas reales de ARCA (simuladas) y el manejo de FEXErr.
class WsfexTest extends ErpTestCase
{
    private function wsfex(): Wsfex
    {
        $afip = new class { public $CUIT = 30712345678; public function GetServiceTA($s) { return (object) ['token' => 'TOK', 'sign' => 'SIG']; } };
        return new Wsfex($afip, false);
    }

    public function test_autoriza_parsea_la_respuesta_y_manda_el_sobre_correcto(): void
    {
        Http::fake(['wswhomo.afip.gov.ar/*' => Http::sequence()
            ->push('<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FEXGetLast_CMPResponse xmlns="http://ar.gov.afip.dif.FEXV1/"><FEXGetLast_CMPResult><FEXResult_LastCMP><Cbte_nro>41</Cbte_nro></FEXResult_LastCMP><FEXErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></FEXErr></FEXGetLast_CMPResult></FEXGetLast_CMPResponse></soap:Body></soap:Envelope>')
            ->push('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FEXGetLast_IDResponse xmlns="http://ar.gov.afip.dif.FEXV1/"><FEXGetLast_IDResult><FEXResultGet><Id>77</Id></FEXResultGet><FEXErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></FEXErr></FEXGetLast_IDResult></FEXGetLast_IDResponse></soap:Body></soap:Envelope>')
            ->push('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FEXAuthorizeResponse xmlns="http://ar.gov.afip.dif.FEXV1/"><FEXAuthorizeResult><FEXResultAuth><Id>78</Id><Cbte_nro>42</Cbte_nro><Cae>71000000000042</Cae><Fch_venc_Cae>20261003</Fch_venc_Cae><Fch_cbte>20260923</Fch_cbte><Resultado>A</Resultado><Reproceso>N</Reproceso></FEXResultAuth><FEXErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></FEXErr></FEXAuthorizeResult></FEXAuthorizeResponse></soap:Body></soap:Envelope>')
            ->push('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><FEXAuthorizeResponse xmlns="http://ar.gov.afip.dif.FEXV1/"><FEXAuthorizeResult><FEXErr><ErrCode>1006</ErrCode><ErrMsg>Fecha del comprobante fuera de rango</ErrMsg></FEXErr></FEXAuthorizeResult></FEXAuthorizeResponse></soap:Body></soap:Envelope>')]);
        $w = $this->wsfex();
        $this->assertSame(41, $w->ultimoComprobante(1, 19));
        $this->assertSame(77, $w->ultimoId());
        $r = $w->autorizar(['Id' => 78, 'Fecha_cbte' => '20260923', 'Cbte_Tipo' => 19, 'Punto_vta' => 1, 'Cbte_nro' => 42, 'Tipo_expo' => 1, 'Permiso_existente' => 'N', 'Dst_cmp' => 203, 'Cliente' => 'Importadora & Cía', 'Cuit_pais_cliente' => 55000002002, 'Domicilio_cliente' => 'Rua 1', 'Id_impositivo' => '', 'Moneda_Id' => 'DOL', 'Moneda_ctz' => 1000, 'Imp_total' => 1200, 'Obs' => '', 'Forma_pago' => 'Contado', 'Incoterms' => 'FOB', 'Incoterms_Ds' => 'FOB', 'Idioma_cbte' => 1, 'Items' => [['Pro_codigo_ea' => 'A1', 'Pro_ds' => 'Miel', 'Pro_qty' => 100, 'Pro_umed' => 7, 'Pro_precio_uni' => 12, 'Pro_total_item' => 1200]]]);
        $this->assertSame('71000000000042', $r['CAE']); $this->assertSame(42, $r['Cbte_nro']); $this->assertSame('A', $r['Resultado']);
        Http::assertSent(function ($req) { $b = $req->body(); return str_contains($b, '<FEXAuthorize xmlns="http://ar.gov.afip.dif.FEXV1/"><Auth><Token>TOK</Token>') && str_contains($b, '<Cliente>Importadora &amp; Cía</Cliente>') && str_contains($b, '<Items><Item><Pro_codigo_ea>A1</Pro_codigo_ea>') && $req->hasHeader('SOAPAction', 'http://ar.gov.afip.dif.FEXV1/FEXAuthorize'); });
        Http::assertSent(fn($req) => str_contains($req->body(), '<FEXGetLast_CMP xmlns="http://ar.gov.afip.dif.FEXV1/"><Auth><Token>TOK</Token><Sign>SIG</Sign><Cuit>30712345678</Cuit><Pto_venta>1</Pto_venta><Cbte_Tipo>19</Cbte_Tipo></Auth>'));
        try { $w->autorizar(['Items' => []]); $this->fail('Debía fallar'); } catch (\RuntimeException $e) { $this->assertSame('(1006) Fecha del comprobante fuera de rango', $e->getMessage()); }
    }
}
