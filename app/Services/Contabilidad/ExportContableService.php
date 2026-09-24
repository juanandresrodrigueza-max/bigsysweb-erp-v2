<?php

namespace App\Services\Contabilidad;

use App\Models\Asiento;
use App\Models\Comprobante;

// Archivos para importar asientos y comprobantes en los sistemas del contador: Tango, Holistor, Bejerman y un CSV genérico.
class ExportContableService
{
    public const FORMATOS = ['tango' => 'Tango Gestión (asientos .txt)', 'holistor' => 'Holistor (asientos .csv)', 'bejerman' => 'Bejerman (asientos .csv)', 'csv' => 'CSV genérico (asientos)', 'iva_csv' => 'Libro IVA ventas y compras (CSV)'];

    private function asientos(string $desde, string $hasta)
    {
        return Asiento::with('lineas.cuenta:id,codigo,nombre', 'lineas.contact:id,name,cuit')->where('estado', 'confirmado')->whereBetween('fecha', [$desde, $hasta])->orderBy('fecha')->orderBy('numero')->get();
    }

    public function generar(string $formato, string $desde, string $hasta): array
    {
        return match ($formato) {
            'tango' => ['asientos_tango_' . $desde . '_' . $hasta . '.txt', $this->tango($desde, $hasta), 'text/plain'],
            'holistor' => ['asientos_holistor_' . $desde . '_' . $hasta . '.csv', $this->holistor($desde, $hasta), 'text/csv'],
            'bejerman' => ['asientos_bejerman_' . $desde . '_' . $hasta . '.csv', $this->bejerman($desde, $hasta), 'text/csv'],
            'iva_csv' => ['libros_iva_' . $desde . '_' . $hasta . '.csv', $this->iva($desde, $hasta), 'text/csv'],
            default => ['asientos_' . $desde . '_' . $hasta . '.csv', $this->csv($desde, $hasta), 'text/csv'],
        };
    }

    // Tango: ancho fijo por línea de asiento: fecha(8) nro(8) cuenta(12) debe(15) haber(15) leyenda(60)
    private function tango(string $desde, string $hasta): string
    {
        $out = '';
        foreach ($this->asientos($desde, $hasta) as $a) foreach ($a->lineas as $l) {
            $out .= $a->fecha->format('Ymd') . str_pad((string) $a->numero, 8, '0', STR_PAD_LEFT) . str_pad(substr((string) $l->cuenta?->codigo, 0, 12), 12) . str_pad(number_format((float) $l->debe, 2, '.', ''), 15, ' ', STR_PAD_LEFT) . str_pad(number_format((float) $l->haber, 2, '.', ''), 15, ' ', STR_PAD_LEFT) . str_pad(mb_substr($l->detalle ?: $a->concepto, 0, 60), 60) . "\r\n";
        }
        return $out;
    }

    private function holistor(string $desde, string $hasta): string
    {
        $out = "Fecha;Asiento;Cuenta;Descripcion cuenta;Debe;Haber;Leyenda\n";
        foreach ($this->asientos($desde, $hasta) as $a) foreach ($a->lineas as $l) $out .= implode(';', [$a->fecha->format('d/m/Y'), $a->numero, $l->cuenta?->codigo, str_replace(';', ',', (string) $l->cuenta?->nombre), number_format((float) $l->debe, 2, ',', ''), number_format((float) $l->haber, 2, ',', ''), str_replace(';', ',', $l->detalle ?: $a->concepto)]) . "\n";
        return "\xEF\xBB\xBF" . $out;
    }

    private function bejerman(string $desde, string $hasta): string
    {
        $out = "TIPO;FECHA;NUMERO;CUENTA;IMPORTE;DH;CONCEPTO;CUIT\n";
        foreach ($this->asientos($desde, $hasta) as $a) foreach ($a->lineas as $l) {
            $imp = (float) $l->debe > 0 ? (float) $l->debe : (float) $l->haber;
            $out .= implode(';', ['AS', $a->fecha->format('d/m/Y'), $a->numero, $l->cuenta?->codigo, number_format($imp, 2, ',', ''), (float) $l->debe > 0 ? 'D' : 'H', str_replace(';', ',', $l->detalle ?: $a->concepto), preg_replace('/\D/', '', (string) $l->contact?->cuit)]) . "\n";
        }
        return "\xEF\xBB\xBF" . $out;
    }

    private function csv(string $desde, string $hasta): string
    {
        $out = "Fecha;Asiento;Concepto;Origen;Cuenta;Nombre cuenta;Debe;Haber;Detalle;Contacto\n";
        foreach ($this->asientos($desde, $hasta) as $a) foreach ($a->lineas as $l) $out .= implode(';', [$a->fecha->format('d/m/Y'), $a->numero, str_replace(';', ',', $a->concepto), $a->origen, $l->cuenta?->codigo, str_replace(';', ',', (string) $l->cuenta?->nombre), number_format((float) $l->debe, 2, ',', ''), number_format((float) $l->haber, 2, ',', ''), str_replace(';', ',', (string) $l->detalle), str_replace(';', ',', (string) $l->contact?->name)]) . "\n";
        return "\xEF\xBB\xBF" . $out;
    }

    private function iva(string $desde, string $hasta): string
    {
        $out = "Libro;Fecha;Tipo;Punto venta;Numero;Contacto;CUIT;Cond IVA;Neto;IVA;Exento;Percepciones;Total;CAE\n";
        $cs = Comprobante::with('contact:id,name,cuit,condicion_iva')->where('estado', 'emitido')->fiscales()->whereIn('tipo', array_keys(array_filter(Comprobante::TIPOS, fn($t) => $t['cc'] !== 0)))->whereBetween('fecha', [$desde, $hasta])->orderBy('direccion')->orderBy('fecha')->get();
        foreach ($cs as $c) { $s = $c->def()['cc'] < 0 ? -1 : 1; $out .= implode(';', [$c->direccion === 'venta' ? 'Ventas' : 'Compras', $c->fecha->format('d/m/Y'), $c->nombreTipo(), $c->punto_venta, $c->numero, str_replace(';', ',', (string) $c->contact?->name), $c->contact?->cuit, $c->contact?->condicion_iva, number_format($s * (float) $c->neto, 2, ',', ''), number_format($s * (float) $c->iva, 2, ',', ''), number_format($s * (float) $c->exento, 2, ',', ''), number_format($s * (float) $c->percepciones, 2, ',', ''), number_format($s * (float) $c->total, 2, ',', ''), $c->cae]) . "\n"; }
        return "\xEF\xBB\xBF" . $out;
    }
}
