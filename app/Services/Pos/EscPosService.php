<?php

namespace App\Services\Pos;

use App\Models\Comprobante;

// Genera los bytes ESC/POS de un ticket para impresoras térmicas de 58/80 mm (Epson, Bixolon, Xprinter, etc.).
class EscPosService
{
    private const ESC = "\x1B"; private const GS = "\x1D";

    public function ticket(Comprobante $c, int $ancho = 42): string
    {
        $b = $c->business; $fmt = fn($n) => '$' . number_format((float) $n, 2, ',', '.');
        $t = fn(string $s) => iconv('UTF-8', 'CP858//TRANSLIT', $s) ?: $s;
        $linea = fn($izq, $der) => $t(mb_substr($izq, 0, $ancho - mb_strlen($der) - 1)) . str_repeat(' ', max(1, $ancho - mb_strlen(mb_substr($izq, 0, $ancho - mb_strlen($der) - 1)) - mb_strlen($der))) . $t($der) . "\n";
        $out = self::ESC . "@" . self::ESC . "t\x13"; // init + code page 858 (acentos y €/$)
        $out .= self::ESC . "a\x01" . self::ESC . "!\x30" . $t($b->name) . "\n" . self::ESC . "!\x00"; // centrado, doble
        if ($b->razon_social && $b->razon_social !== $b->name) $out .= $t($b->razon_social) . "\n";
        if ($b->cuit) $out .= 'CUIT ' . $b->cuit . ' - ' . $t($b->condicion_iva ?? '') . "\n";
        if ($b->address) $out .= $t($b->address) . "\n";
        $out .= str_repeat('-', $ancho) . "\n" . self::ESC . "!\x08" . $t($c->nombreTipo()) . ' ' . $c->numeroFormateado() . self::ESC . "!\x00\n";
        $out .= ($c->emitido_en ?? $c->created_at)->format('d/m/Y H:i') . ($c->user ? ' - ' . $t($c->user->name) : '') . "\n";
        if ($c->contact && $c->contact->name !== 'Consumidor Final') $out .= $t($c->contact->name) . ($c->contact->cuit ? ' - ' . $c->contact->cuit : '') . "\n";
        $out .= self::ESC . "a\x00" . str_repeat('-', $ancho) . "\n";
        foreach ($c->items as $it) {
            $cant = rtrim(rtrim(number_format((float) $it->cantidad, 3, ',', '.'), '0'), ',');
            $out .= $t(mb_substr($it->descripcion, 0, $ancho)) . "\n" . $linea("  {$cant} x " . $fmt($c->esFactura() && $c->def()['letra'] !== 'A' ? (float) $it->precio_unit * (1 + (float) $it->alicuota_iva / 100) : (float) $it->precio_unit), $fmt($c->def()['letra'] === 'A' ? (float) $it->neto : (float) $it->total));
        }
        $out .= str_repeat('-', $ancho) . "\n";
        if ($c->def()['letra'] === 'A') { $out .= $linea('Neto', $fmt($c->neto)) . $linea('IVA', $fmt($c->iva)); if ((float) $c->percepciones > 0) $out .= $linea('Percepciones', $fmt($c->percepciones)); }
        $out .= self::ESC . "!\x30" . $linea('TOTAL', $fmt($c->total)) . self::ESC . "!\x00";
        foreach ($c->imputaciones as $imp) { if ($imp->cobro) foreach ($imp->cobro->medios as $m) $out .= $linea(ucfirst($m->medio), $fmt($m->monto)); }
        if ($c->cae) $out .= str_repeat('-', $ancho) . "\nCAE " . $c->cae . ($c->cae_vto ? ' Vto ' . $c->cae_vto->format('d/m/Y') : '') . "\n";
        elseif (! $c->esFiscal()) $out .= "Documento no valido como factura\n";
        $out .= self::ESC . "a\x01\n" . $t('Gracias por su compra!') . "\n";
        if ($url = $c->urlPublica()) $out .= $this->qr($url);
        $out .= "\n\n\n" . self::GS . "V\x41\x03"; // corte parcial
        return $out;
    }

    // QR nativo ESC/POS (modelo 2, tamaño 4, corrección M)
    private function qr(string $data): string
    {
        $len = strlen($data) + 3; $pL = chr($len % 256); $pH = chr(intdiv($len, 256));
        return self::GS . "(k\x04\x00\x31\x41\x32\x00" . self::GS . "(k\x03\x00\x31\x43\x04" . self::GS . "(k\x03\x00\x31\x45\x31" . self::GS . "(k" . $pL . $pH . "\x31\x50\x30" . $data . self::GS . "(k\x03\x00\x31\x51\x30" . "\n";
    }
}
