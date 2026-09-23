<?php

namespace App\Services\Afip;

use App\Models\Comprobante;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Código QR obligatorio en las facturas electrónicas (RG 4892): un JSON en base64 dentro de la URL de ARCA.
class QrArca
{
    public static function datos(Comprobante $c): ?array
    {
        if (! $c->cae || ! $c->afipTipo() || ! $c->numero) return null;
        $b = $c->emisor(); $cli = $c->contact;
        $cuitCli = $cli?->cuit ? preg_replace('/\D/', '', $cli->cuit) : null;
        return [
            'ver' => 1,
            'fecha' => $c->fecha->format('Y-m-d'),
            'cuit' => (int) preg_replace('/\D/', '', (string) $b->cuit),
            'ptoVta' => (int) $c->punto_venta,
            'tipoCmp' => (int) $c->afipTipo(),
            'nroCmp' => (int) $c->numero,
            'importe' => round((float) $c->total, 2),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => $cuitCli ? 80 : 99,
            'nroDocRec' => $cuitCli ? (int) $cuitCli : 0,
            'tipoCodAut' => 'E',
            'codAut' => (int) $c->cae,
        ];
    }

    public static function url(Comprobante $c): ?string
    {
        $d = self::datos($c);
        return $d ? 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($d)) : null;
    }

    // Imagen PNG en data URI (dompdf la dibuja); null si el comprobante no tiene CAE.
    public static function imagen(Comprobante $c, int $escala = 3): ?string
    {
        $url = self::url($c);
        if (! $url) return null;
        $opt = new QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG, 'scale' => $escala, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'outputBase64' => true, 'addQuietzone' => true, 'quietzoneSize' => 1]);
        return (new QRCode($opt))->render($url);
    }
}
