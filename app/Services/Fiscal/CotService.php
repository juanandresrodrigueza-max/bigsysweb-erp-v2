<?php

namespace App\Services\Fiscal;

use App\Models\Comprobante;

// Remito electrónico: archivo para obtener el COT (Código de Operación de Traslado) de ARBA, según el diseño de registro
// del aplicativo/web service "Remito Electrónico" (registros 01 encabezado, 02 remito, 03 productos, 04 pie). Se sube en la web de ARBA
// o por web service y el código devuelto se guarda en el remito.
class CotService
{
    public function archivo(Comprobante $c): string
    {
        abort_unless($c->tipo === 'REM' && $c->estado === 'emitido', 422, 'Solo se genera COT para remitos emitidos.');
        $b = $c->business; $cli = $c->contact;
        $cuitEmp = preg_replace('/\D/', '', (string) $b->cuit); $cuitCli = preg_replace('/\D/', '', (string) ($cli?->cuit ?? ''));
        $fecha = $c->fecha->format('Ymd'); $hora = ($c->emitido_en ?? now())->format('Hi');
        $codigoUnico = 'R' . str_pad((string) $c->punto_venta, 4, '0', STR_PAD_LEFT) . str_pad((string) $c->numero, 8, '0', STR_PAD_LEFT);
        $limpiar = fn($v, $n) => mb_substr(preg_replace('/[|\r\n]/', ' ', (string) $v), 0, $n);
        $lineas = [];
        $lineas[] = implode('|', ['01', $cuitEmp]);
        $lineas[] = implode('|', [
            '02', $fecha, $codigoUnico, $fecha, $hora, 'E', // fecha emisión, código único, fecha salida, hora, sujeto generador (E = emisor)
            $cuitCli, $limpiar($cli?->name ?? 'Consumidor final', 50), '0', // destinatario: cuit, razón social, tenedor (0 no)
            $limpiar($b->address ?? '', 40), $limpiar($b->city ?? '', 40), $limpiar($b->postal_code ?? '', 8), // origen
            $limpiar($c->domicilio_entrega ?: trim(($cli?->address ?? '') . ' ' . ($cli?->city ?? '')), 40), $limpiar($cli?->city ?? '', 40), $limpiar($cli?->postal_code ?? '', 8), // destino
            preg_replace('/\D/', '', (string) $c->transportista_cuit), $limpiar($c->transportista ?? '', 50), strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $c->patente)),
            (string) ((int) $c->bultos ?: $c->items->count()), number_format((float) $c->peso_kg, 2, '.', ''), '1', // bultos, peso, tipo de operación (1 venta)
            number_format((float) $c->total, 2, '.', ''), $limpiar($c->notas ?? '', 100),
        ]);
        foreach ($c->items as $it) {
            $lineas[] = implode('|', ['03', $limpiar($it->product?->sku ?? $it->product_id ?? '', 20), $limpiar($it->descripcion, 60), number_format((float) $it->cantidad, 2, '.', ''), $limpiar($it->unidad ?? 'un', 5)]);
        }
        $lineas[] = implode('|', ['04', (string) $c->items->count()]);
        return implode("\r\n", $lineas) . "\r\n";
    }

    public function nombreArchivo(Comprobante $c): string
    {
        // TB_<cuit>_<sucursal>_<fecha>_<secuencia>.txt es el nombre que espera ARBA.
        return 'TB_' . preg_replace('/\D/', '', (string) $c->business->cuit) . '_' . str_pad((string) $c->punto_venta, 3, '0', STR_PAD_LEFT) . '_' . $c->fecha->format('Ymd') . '_' . str_pad((string) $c->numero, 6, '0', STR_PAD_LEFT) . '.txt';
    }
}
