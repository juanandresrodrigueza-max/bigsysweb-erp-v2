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

    // ---- SIFERE WEB (Convenio Multilateral): lo que le retuvieron y percibieron a la empresa ----
    // Retenciones de IIBB sufridas: las que los clientes descontaron al pagar (medio "retención" en los cobros).
    public function retencionesSufridas(string $desde, string $hasta): \Illuminate\Support\Collection
    {
        $b = \Illuminate\Support\Facades\Auth::user()->business;
        $def = app(\App\Services\Fiscal\ImpuestosService::class)->config($b)['percepcion_iibb']['jurisdiccion'] ?? 'ARBA';
        return \App\Models\CobroMedio::with('cobro.contact')->where('medio', 'retencion')
            ->whereHas('cobro', fn($q) => $q->where('estado', '!=', 'anulado')->whereBetween('fecha', [$desde, $hasta]))->get()
            ->filter(fn($m) => in_array($m->datos['impuesto'] ?? 'iibb', ['iibb', null, ''], true))
            ->map(fn($m) => ['fecha' => $m->cobro->fecha, 'cuit' => $m->cobro->contact?->cuit, 'agente' => $m->cobro->contact?->name, 'jurisdiccion' => \App\Support\JurisdiccionesIibb::codigo($m->datos['jurisdiccion'] ?? $m->cobro->contact?->jurisdiccion_iibb ?? $def),
                'constancia' => $m->datos['certificado'] ?? $m->referencia ?? (string) $m->cobro->numero, 'monto' => (float) $m->monto, 'recibo' => $m->cobro->numeroFormateado(), 'cobro_id' => $m->cobro_id])
            ->sortBy('fecha')->values();
    }

    // Percepciones de IIBB sufridas: las que los proveedores agregaron en sus facturas de compra.
    public function percepcionesSufridas(string $desde, string $hasta): \Illuminate\Support\Collection
    {
        return \App\Models\ComprobanteImpuesto::with('comprobante.contact')->where('tipo', 'like', 'iibb%')
            ->whereHas('comprobante', fn($q) => $q->where('direccion', 'compra')->where('estado', 'emitido')->whereBetween('fecha', [$desde, $hasta]))->get()
            ->map(function ($i) {
                $c = $i->comprobante; [$pv, $nro] = self::pvNumero($c->numero_proveedor, $c->punto_venta, $c->numero);
                return ['fecha' => $c->fecha, 'cuit' => $c->contact?->cuit, 'agente' => $c->contact?->name, 'jurisdiccion' => \App\Support\JurisdiccionesIibb::codigo(str_starts_with($i->tipo, 'iibb_') ? substr($i->tipo, 5) : ($c->contact?->jurisdiccion_iibb ?? 'ARBA')),
                    'tipo' => $c->def()['grupo'] === 'nc' ? 'C' : ($c->def()['grupo'] === 'nd' ? 'D' : 'F'), 'letra' => $c->def()['letra'] ?: 'A', 'pv' => $pv, 'numero' => $nro, 'monto' => (float) $i->monto * ($c->def()['grupo'] === 'nc' ? -1 : 1), 'comprobante_id' => $c->id];
            })->sortBy('fecha')->values();
    }

    private static function pvNumero(?string $numeroProveedor, $pv, $numero): array
    {
        if ($numeroProveedor && preg_match('/(\d{1,5})\D+(\d{1,8})\s*$/', $numeroProveedor, $m)) return [(int) $m[1], (int) $m[2]];
        return [(int) $pv, (int) $numero];
    }

    // Importe de SIFERE: 11 posiciones con coma decimal y signo si es negativo ("00001234,56" / "-0001234,56").
    private static function importeSifere(float $v): string
    {
        $s = number_format(abs($v), 2, ',', '');
        return $v < 0 ? '-' . str_pad($s, 10, '0', STR_PAD_LEFT) : str_pad($s, 11, '0', STR_PAD_LEFT);
    }

    private static function cuitGuiones(?string $c): string
    {
        $d = preg_replace('/\D/', '', (string) $c);
        return strlen($d) === 11 ? substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10) : str_pad($d, 13);
    }

    // Formato de importación de retenciones de SIFERE WEB: jurisdicción(3) CUIT agente(13) fecha(10) sucursal(4) constancia(16) tipo(1) letra(1) comprobante original(20) importe(11).
    public function sifereRetenciones(string $desde, string $hasta): string
    {
        $out = '';
        foreach ($this->retencionesSufridas($desde, $hasta) as $r) {
            if (! $r['jurisdiccion']) continue;
            $out .= $r['jurisdiccion'] . self::cuitGuiones($r['cuit']) . $r['fecha']->format('d/m/Y') . '0000' . str_pad(substr(preg_replace('/\D/', '', (string) $r['constancia']) ?: '0', -16), 16, '0', STR_PAD_LEFT)
                . 'R' . ' ' . str_pad('', 20, '0') . self::importeSifere($r['monto']) . "\r\n";
        }
        return $out;
    }

    // Formato de importación de percepciones de SIFERE WEB: jurisdicción(3) CUIT agente(13) fecha(10) punto de venta(4) número(8) tipo(1) letra(1) importe(11).
    public function siferePercepciones(string $desde, string $hasta): string
    {
        $out = '';
        foreach ($this->percepcionesSufridas($desde, $hasta) as $p) {
            if (! $p['jurisdiccion']) continue;
            $out .= $p['jurisdiccion'] . self::cuitGuiones($p['cuit']) . $p['fecha']->format('d/m/Y') . str_pad((string) $p['pv'], 4, '0', STR_PAD_LEFT) . str_pad((string) $p['numero'], 8, '0', STR_PAD_LEFT)
                . $p['tipo'] . $p['letra'] . self::importeSifere($p['monto']) . "\r\n";
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
        $cs = Comprobante::ventas()->emitidos()->fiscales()->with('contact', 'impuestos')->whereBetween('fecha', [$desde, $hasta])->whereHas('impuestos', fn($q) => $q->where('tipo', 'like', 'iibb%')->orWhere('tipo', 'like', 'perc_%'))->orderBy('fecha')->get();
        foreach ($cs as $c) {
            foreach ($c->impuestos->filter(fn($i) => str_starts_with($i->tipo, 'iibb') || str_starts_with($i->tipo, 'perc_')) as $i) {
                $signo = $c->def()['cc'] < 0 ? -1 : 1;
                $out .= implode(';', [$c->fecha->format('d/m/Y'), preg_replace('/\D/', '', (string) $c->contact?->cuit), $c->def()['grupo'] === 'nc' ? 'C' : 'F', $c->def()['letra'], str_pad((string) $c->punto_venta, 5, '0', STR_PAD_LEFT), str_pad((string) $c->numero, 8, '0', STR_PAD_LEFT), number_format($signo * (float) $i->base, 2, ',', ''), number_format((float) $i->alicuota, 2, ',', ''), number_format($signo * (float) $i->monto, 2, ',', ''), \App\Models\ComprobanteImpuesto::etiqueta($i->tipo)]) . "\r\n";
            }
        }
        return $out;
    }

    private function imp(float $v, int $len): string { return str_pad(number_format($v, 2, ',', ''), $len, '0', STR_PAD_LEFT); }
}
