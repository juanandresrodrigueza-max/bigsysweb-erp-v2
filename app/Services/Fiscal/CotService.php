<?php

namespace App\Services\Fiscal;

use App\Models\Business;
use App\Models\Comprobante;
use Illuminate\Support\Facades\Http;

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

    // --- Web service de ARBA: se sube el archivo y devuelve el COT en el momento ---
    private const URL_PROD = 'https://cot.arba.gov.ar/TransporteBienes/SeguridadCliente/presentarRemitos.do';
    private const URL_TEST = 'http://cot.test.arba.gov.ar/TransporteBienes/SeguridadCliente/presentarRemitos.do';

    public function configurado(Business $b): bool { return ! empty($b->arba_settings['clave']); }

    public function pedir(Comprobante $c): array
    {
        $b = $c->business;
        abort_unless($this->configurado($b), 422, 'Cargá la clave CIT de ARBA en Configuración → Impuestos para pedir el COT desde acá.');
        $archivo = $this->archivo($c); $nombre = $this->nombreArchivo($c);
        $url = ($b->arba_settings['produccion'] ?? true) ? self::URL_PROD : self::URL_TEST;
        $r = Http::timeout(30)->asMultipart()->attach('file', $archivo, $nombre)->post($url, ['user' => preg_replace('/\D/', '', (string) ($b->arba_settings['usuario'] ?: $b->cuit)), 'password' => $b->arba_settings['clave']]);
        if (! $r->successful()) throw new \RuntimeException("ARBA respondió HTTP {$r->status()}.");
        $res = $this->interpretar($r->body());
        if ($res['error']) throw new \RuntimeException('ARBA rechazó el remito: ' . $res['error']);
        $c->forceFill(['cot' => $res['cot']])->save();
        \App\Models\AuditLog::registrar('editar', $c, "COT {$res['cot']} obtenido de ARBA por web service");
        return $res;
    }

    // Respuesta XML de ARBA: <COT><tipoError/><codigoError/><mensajeError/><validacionesRemitos><remito><numeroUnico/><procesado>SI|NO</procesado><cot/><errores>...</errores></remito>...
    public function interpretar(string $xml): array
    {
        $x = @simplexml_load_string(trim($xml));
        if (! $x) return ['cot' => null, 'error' => 'Respuesta ilegible de ARBA: ' . mb_substr(strip_tags($xml), 0, 120)];
        if (! empty((string) $x->mensajeError) || (! empty((string) $x->tipoError) && (string) $x->tipoError !== '' && (string) $x->tipoError !== '0')) return ['cot' => null, 'error' => trim((string) $x->mensajeError ?: (string) $x->tipoError)];
        $rem = $x->validacionesRemitos->remito[0] ?? null;
        if (! $rem) return ['cot' => null, 'error' => 'ARBA no devolvió el remito.'];
        if (strtoupper((string) $rem->procesado) !== 'SI' || empty((string) $rem->cot)) {
            $errs = []; foreach ($rem->errores->error ?? [] as $e) $errs[] = trim((string) $e->descripcion ?: (string) $e);
            return ['cot' => null, 'error' => implode(' · ', array_filter($errs)) ?: 'Remito no procesado.'];
        }
        return ['cot' => (string) $rem->cot, 'numero_unico' => (string) $rem->numeroUnico, 'error' => null];
    }

    public function nombreArchivo(Comprobante $c): string
    {
        // TB_<cuit>_<sucursal>_<fecha>_<secuencia>.txt es el nombre que espera ARBA.
        return 'TB_' . preg_replace('/\D/', '', (string) $c->business->cuit) . '_' . str_pad((string) $c->punto_venta, 3, '0', STR_PAD_LEFT) . '_' . $c->fecha->format('Ymd') . '_' . str_pad((string) $c->numero, 6, '0', STR_PAD_LEFT) . '.txt';
    }
}
