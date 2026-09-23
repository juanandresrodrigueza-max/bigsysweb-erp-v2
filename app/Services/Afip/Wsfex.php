<?php

namespace App\Services\Afip;

use Afip;
use Illuminate\Support\Facades\Http;

// WSFEX: factura electrónica de exportación (tipos 19, 20 y 21). El SDK no lo trae, así que el SOAP se arma a mano y se manda por HTTP.
// El token y la firma salen del mismo WSAA que WSFE (servicio "wsfex").
class Wsfex
{
    private const NS = 'http://ar.gov.afip.dif.FEXV1/';
    private const URL_PROD = 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx';
    private const URL_HOMO = 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx';

    public function __construct(private object $afip, private bool $produccion) {}

    private function auth(): string
    {
        $ta = $this->afip->GetServiceTA('wsfex');
        return '<Auth><Token>' . htmlspecialchars($ta->token) . '</Token><Sign>' . htmlspecialchars($ta->sign) . '</Sign><Cuit>' . (int) $this->afip->CUIT . '</Cuit></Auth>';
    }

    // Llama una operación y devuelve el nodo de resultado como SimpleXML. Los errores de ARCA (FEXErr) se lanzan como excepción.
    public function llamar(string $operacion, string $cuerpo = '', bool $conAuth = true): \SimpleXMLElement
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><' . $operacion . ' xmlns="' . self::NS . '">' . ($conAuth ? $this->auth() : '') . $cuerpo . '</' . $operacion . '></soap:Body></soap:Envelope>';
        $r = Http::withHeaders(['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => self::NS . $operacion])->timeout(40)->withBody($xml, 'text/xml')->post($this->produccion ? self::URL_PROD : self::URL_HOMO);
        if (! $r->successful()) throw new \RuntimeException("WSFEX HTTP {$r->status()}: " . mb_substr(strip_tags($r->body()), 0, 200));
        $doc = @simplexml_load_string(preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$3', $r->body())); // sin prefijos de namespace
        if (! $doc) throw new \RuntimeException('WSFEX: respuesta ilegible.');
        $res = $doc->Body->{$operacion . 'Response'}->{$operacion . 'Result'} ?? null;
        if (! $res) { $f = $doc->Body->Fault ?? null; throw new \RuntimeException('WSFEX: ' . ($f ? (string) $f->faultstring : 'sin resultado')); }
        if (isset($res->FEXErr) && (int) $res->FEXErr->ErrCode !== 0) throw new \RuntimeException('(' . (int) $res->FEXErr->ErrCode . ') ' . (string) $res->FEXErr->ErrMsg);
        return $res;
    }

    public function ultimoComprobante(int $pv, int $tipo): int
    {
        // En esta operación el punto de venta y el tipo van adentro del Auth (así lo define el WSDL de WSFEX).
        $ta = $this->afip->GetServiceTA('wsfex');
        $r = $this->llamar('FEXGetLast_CMP', '<Auth><Token>' . htmlspecialchars($ta->token) . '</Token><Sign>' . htmlspecialchars($ta->sign) . '</Sign><Cuit>' . (int) $this->afip->CUIT . '</Cuit><Pto_venta>' . $pv . '</Pto_venta><Cbte_Tipo>' . $tipo . '</Cbte_Tipo></Auth>', false);
        return (int) ($r->FEXResult_LastCMP->Cbte_nro ?? 0);
    }

    public function ultimoId(): int
    {
        $r = $this->llamar('FEXGetLast_ID');
        return (int) ($r->FEXResultGet->Id ?? 0);
    }

    // $d: los campos del elemento Cmp (ver AfipEmisor::armarDatosFex).
    public function autorizar(array $d): array
    {
        $x = fn($v) => htmlspecialchars((string) $v, ENT_XML1);
        $items = '';
        foreach ($d['Items'] as $i) { $items .= '<Item>'; foreach ($i as $k => $v) $items .= "<{$k}>{$x($v)}</{$k}>"; $items .= '</Item>'; }
        $cmp = '<Cmp>';
        foreach ($d as $k => $v) { if ($k === 'Items') $cmp .= '<Items>' . $items . '</Items>'; elseif ($k === 'Permisos') { $cmp .= '<Permisos>'; foreach ($v as $a) { $cmp .= '<Permiso>'; foreach ($a as $ak => $av) $cmp .= "<{$ak}>{$x($av)}</{$ak}>"; $cmp .= '</Permiso>'; } $cmp .= '</Permisos>'; } elseif ($k === 'Cmps_asoc') { $cmp .= '<Cmps_asoc>'; foreach ($v as $a) { $cmp .= '<Cmp_asoc>'; foreach ($a as $ak => $av) $cmp .= "<{$ak}>{$x($av)}</{$ak}>"; $cmp .= '</Cmp_asoc>'; } $cmp .= '</Cmps_asoc>'; } elseif ($v !== null && $v !== '') $cmp .= "<{$k}>{$x($v)}</{$k}>"; }
        $cmp .= '</Cmp>';
        $r = $this->llamar('FEXAuthorize', $cmp);
        $a = $r->FEXResultAuth;
        return ['Resultado' => (string) $a->Resultado, 'CAE' => (string) $a->Cae, 'CAEFchVto' => (string) $a->Fch_venc_Cae, 'Cbte_nro' => (int) $a->Cbte_nro, 'Reproceso' => (string) $a->Reproceso, 'Motivos_Obs' => (string) ($a->Motivos_Obs ?? ''), 'Id' => (int) $a->Id];
    }

    public function consultar(int $pv, int $tipo, int $nro): ?array
    {
        $r = $this->llamar('FEXGetCMP', '<Cmp><Cbte_tipo>' . $tipo . '</Cbte_tipo><Punto_vta>' . $pv . '</Punto_vta><Cbte_nro>' . $nro . '</Cbte_nro></Cmp>');
        $g = $r->FEXResultGet ?? null;
        return $g ? ['CodAutorizacion' => (string) $g->Cae, 'FchVto' => (string) $g->Fch_venc_Cae, 'ImpTotal' => (float) $g->Imp_total, 'CbteFch' => (string) $g->Fecha_cbte, 'Resultado' => (string) ($g->Resultado ?? 'A')] : null;
    }
}
