<?php

namespace App\Services\Fiscal;

use App\Models\Comprobante;
use App\Models\Retencion;

// Archivos para los aplicativos fiscales: SICORE (retenciones nacionales), SIRCAR (IIBB convenio) y percepciones IIBB (ARBA/AGIP).
class ExportacionesService
{
    private const SICORE_IMPUESTO = ['ganancias' => ['217', '078'], 'iva' => ['767', '499']]; // [impuesto, régimen] por defecto

    public function retenciones(string $desde, string $hasta, ?string $tipo = null)
    {
        return Retencion::with(['contact', 'pago'])->whereBetween('fecha', [$desde, $hasta])->when($tipo, fn($q, $t) => $q->where('tipo', $t))->whereHas('pago', fn($q) => $q->where('estado', '!=', 'anulado'))->orderBy('fecha')->orderBy('id')->get();
    }

    // SICORE: registro de retenciones de ancho fijo (formato RG 2233 / 4523).
    public function sicore(string $desde, string $hasta): string
    {
        $out = '';
        foreach ($this->retenciones($desde, $hasta) as $r) {
            if (! in_array($r->tipo, ['ganancias', 'iva'], true)) continue;
            [$imp, $reg] = self::SICORE_IMPUESTO[$r->tipo];
            $cuit = preg_replace('/\D/', '', (string) $r->contact?->cuit);
            $out .= '06' . $r->fecha->format('d/m/Y') . str_pad((string) ($r->pago?->numero ?? 0), 16, '0', STR_PAD_LEFT)
                . $this->imp((float) $r->base, 16) . $imp . $reg . '1' . $this->imp((float) $r->base, 14) . $r->fecha->format('d/m/Y') . '2'
                . $this->imp((float) $r->monto, 14) . '000000' . str_pad((string) ($r->id), 14, '0', STR_PAD_LEFT) . '80' . str_pad($cuit, 20) . '  ' . $r->fecha->format('d/m/Y') . str_repeat(' ', 30) . "\r\n";
        }
        return $out;
    }

    // SIRCAR: CSV de retenciones de IIBB (convenio multilateral) para cargar en el aplicativo.
    public function sircar(string $desde, string $hasta): string
    {
        $out = '';
        $n = 1;
        foreach ($this->retenciones($desde, $hasta, 'iibb') as $r) {
            $cuit = preg_replace('/\D/', '', (string) $r->contact?->cuit);
            $out .= implode(',', [$n++, ($r->jurisdiccion ?? 'CM'), $cuit, $r->fecha->format('d/m/Y'), str_pad((string) ($r->pago?->numero ?? 0), 12, '0', STR_PAD_LEFT), '3', number_format((float) $r->base, 2, '.', ''), number_format((float) $r->alicuota, 2, '.', ''), number_format((float) $r->monto, 2, '.', ''), $r->certificado ?? '']) . "\r\n";
        }
        return $out;
    }

    // Percepciones de IIBB cobradas en ventas: formato ARBA/AGIP (fecha, cuit, comprobante, base, alícuota, monto).
    public function percepciones(string $desde, string $hasta): string
    {
        $out = '';
        $cs = Comprobante::ventas()->emitidos()->with('contact', 'impuestos')->whereBetween('fecha', [$desde, $hasta])->whereHas('impuestos', fn($q) => $q->where('tipo', 'like', 'iibb%'))->orderBy('fecha')->get();
        foreach ($cs as $c) {
            foreach ($c->impuestos->filter(fn($i) => str_starts_with($i->tipo, 'iibb')) as $i) {
                $signo = $c->def()['cc'] < 0 ? -1 : 1;
                $out .= implode(';', [$c->fecha->format('d/m/Y'), preg_replace('/\D/', '', (string) $c->contact?->cuit), $c->def()['grupo'] === 'nc' ? 'C' : 'F', $c->def()['letra'], str_pad((string) $c->punto_venta, 5, '0', STR_PAD_LEFT), str_pad((string) $c->numero, 8, '0', STR_PAD_LEFT), number_format($signo * (float) $i->base, 2, ',', ''), number_format((float) $i->alicuota, 2, ',', ''), number_format($signo * (float) $i->monto, 2, ',', ''), strtoupper(str_replace('iibb_', '', $i->tipo === 'iibb' ? 'ARBA' : $i->tipo))]) . "\r\n";
            }
        }
        return $out;
    }

    private function imp(float $v, int $len): string { return str_pad(number_format($v, 2, ',', ''), $len, '0', STR_PAD_LEFT); }
}
