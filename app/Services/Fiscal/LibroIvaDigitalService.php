<?php

namespace App\Services\Fiscal;

use App\Models\Business;
use App\Models\Comprobante;

// Genera los archivos del Libro IVA Digital (RG 4597): comprobantes y alícuotas, ventas y compras, en el formato de ancho fijo de ARCA.
class LibroIvaDigitalService
{
    private const ALIC = ['0' => '0003', '2.5' => '0009', '5' => '0008', '10.5' => '0004', '21' => '0005', '27' => '0006'];

    private function n(float $v, int $len = 15): string { return str_pad((string) round(abs($v) * 100), $len, '0', STR_PAD_LEFT); }
    private function s(?string $v, int $len): string { return str_pad(mb_substr(mb_strtoupper((string) $v), 0, $len), $len, ' '); }
    private function d(int $v, int $len): string { return str_pad((string) $v, $len, '0', STR_PAD_LEFT); }

    public function ventas(Business $b, string $desde, string $hasta): array
    {
        $cbtes = ''; $alics = '';
        foreach ($this->comprobantes('venta', $desde, $hasta) as $c) {
            [$doc, $nro] = $this->documento($c);
            $porAl = $this->porAlicuota($c);
            $exento = (float) $c->exento + (float) ($porAl['0']['neto'] ?? 0);
            $cbtes .= $c->fecha->format('Ymd') . $this->d($c->afipTipo() ?? 0, 3) . $this->d((int) $c->punto_venta, 5) . $this->d((int) $c->numero, 20) . $this->d((int) $c->numero, 20) . str_repeat(' ', 16)
                . $this->d($doc, 2) . $this->d((int) $nro, 20) . $this->s($c->contact?->name ?? 'CONSUMIDOR FINAL', 30)
                . $this->n((float) $c->total) . $this->n(0) . $this->n(0) . $this->n($exento) . $this->n(0) . $this->n((float) $c->percepciones) . $this->n(0) . $this->n(0)
                . 'PES' . '0001000000' . (string) max(1, count(array_filter(array_keys($porAl), fn($k) => (float) $k > 0))) . ($exento > 0 && ! $c->iva ? 'E' : ' ') . $this->n(0) . ($c->fce_vto_pago ?? $c->fecha_vto ?? $c->fecha)->format('Ymd') . "\r\n";
            foreach ($porAl as $al => $v) {
                if ((float) $al <= 0) continue;
                $alics .= $this->d($c->afipTipo() ?? 0, 3) . $this->d((int) $c->punto_venta, 5) . $this->d((int) $c->numero, 20) . $this->n($v['neto']) . (self::ALIC[$al] ?? '0005') . $this->n($v['iva']) . "\r\n";
            }
        }
        return ['LIBRO_IVA_DIGITAL_VENTAS_CBTE.txt' => $cbtes, 'LIBRO_IVA_DIGITAL_VENTAS_ALICUOTAS.txt' => $alics];
    }

    public function compras(Business $b, string $desde, string $hasta): array
    {
        $cbtes = ''; $alics = '';
        foreach ($this->comprobantes('compra', $desde, $hasta) as $c) {
            [$doc, $nro] = $this->documento($c);
            [$pv, $num] = array_pad(explode('-', (string) $c->numero_proveedor), 2, '0');
            $porAl = $this->porAlicuota($c);
            $percIva = (float) $c->impuestos->where('tipo', 'iva')->sum('monto'); $percIibb = (float) $c->impuestos->filter(fn($i) => str_starts_with($i->tipo, 'iibb'))->sum('monto'); $otros = (float) $c->impuestos->whereNotIn('tipo', ['iva'])->filter(fn($i) => ! str_starts_with($i->tipo, 'iibb'))->sum('monto');
            $cbtes .= $c->fecha->format('Ymd') . $this->d($c->afipTipo() ?? 0, 3) . $this->d((int) $pv, 5) . $this->d((int) $num, 20) . str_repeat(' ', 16)
                . $this->d($doc, 2) . $this->d((int) $nro, 20) . $this->s($c->contact?->name, 30)
                . $this->n((float) $c->total) . $this->n(0) . $this->n((float) $c->exento) . $this->n($percIva) . $this->n(0) . $this->n($percIibb) . $this->n(0) . $this->n(0)
                . 'PES' . '0001000000' . (string) max(1, count(array_filter(array_keys($porAl), fn($k) => (float) $k > 0))) . ' ' . $this->n((float) $c->iva) . $this->n($otros) . $this->d(0, 11) . $this->s('', 30) . $this->n(0) . "\r\n";
            foreach ($porAl as $al => $v) {
                if ((float) $al <= 0) continue;
                $alics .= $this->d($c->afipTipo() ?? 0, 3) . $this->d((int) $pv, 5) . $this->d((int) $num, 20) . $this->d($doc, 2) . $this->d((int) $nro, 20) . $this->n($v['neto']) . (self::ALIC[$al] ?? '0005') . $this->n($v['iva']) . "\r\n";
            }
        }
        return ['LIBRO_IVA_DIGITAL_COMPRAS_CBTE.txt' => $cbtes, 'LIBRO_IVA_DIGITAL_COMPRAS_ALICUOTAS.txt' => $alics];
    }

    private function comprobantes(string $direccion, string $desde, string $hasta)
    {
        return Comprobante::with('contact', 'items', 'impuestos')->where('direccion', $direccion)->where('estado', 'emitido')
            ->whereIn('tipo', array_keys(array_filter(Comprobante::TIPOS, fn($t) => $t['afip'] !== null)))->whereBetween('fecha', [$desde, $hasta])->orderBy('fecha')->orderBy('id')->get();
    }

    private function documento(Comprobante $c): array
    {
        $cuit = preg_replace('/\D/', '', (string) $c->contact?->cuit);
        if (strlen($cuit) === 11) return [80, $cuit];
        $dni = preg_replace('/\D/', '', (string) $c->contact?->document);
        return $dni ? [96, $dni] : [99, 0];
    }

    private function porAlicuota(Comprobante $c): array
    {
        $out = [];
        foreach ($c->items as $it) { $k = (string) (float) $it->alicuota_iva; $out[$k] ??= ['neto' => 0, 'iva' => 0]; $out[$k]['neto'] += (float) $it->neto; $out[$k]['iva'] += (float) $it->iva; }
        return $out;
    }

    // Empaqueta los archivos en un zip listo para subir a ARCA.
    public function zip(array $archivos, string $nombre): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lid') . '.zip';
        $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($archivos as $n => $contenido) $z->addFromString($n, $contenido);
        $z->close();
        return $tmp;
    }
}
