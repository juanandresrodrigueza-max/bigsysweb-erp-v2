<?php

namespace App\Services\Comprobantes;

use App\Models\Business;
use App\Models\Comprobante;
use App\Services\Afip\AfipErrores;
use App\Services\Afip\AfipService;
use Illuminate\Support\Facades\Log;

// Pide el CAE a ARCA (WSFE). Sin certificado cargado trabaja en modo simulado; si ARCA no responde, el comprobante queda pendiente y se reintenta.
class AfipEmisor
{
    private const ALICUOTAS = ['0' => 3, '2.5' => 9, '5' => 8, '10.5' => 4, '21' => 5, '27' => 6];
    // RG 5616: condición frente al IVA del receptor.
    public const CONDICION_RECEPTOR = ['Responsable Inscripto' => 1, 'Exento' => 4, 'Consumidor Final' => 5, 'Monotributista' => 6, 'No Responsable' => 7, 'No Categorizado' => 7, 'Monotributista Social' => 13, 'IVA No Alcanzado' => 15];

    public function configurado(Business $b): bool
    {
        return (bool) ($b->cuit && $b->afip_cert_path && $b->afip_key_path);
    }

    public function emitir(Comprobante $c, Business $b): array
    {
        $afipId = $c->afipTipo();
        if (! $afipId) return ['estado' => 'no_aplica', 'numero' => null];
        if (! $this->configurado($b)) return ['estado' => 'simulado', 'numero' => null, 'cae' => null, 'cae_vto' => null];
        if ($c->tipo === 'FE') return ['estado' => 'simulado', 'numero' => null, 'cae' => null, 'cae_vto' => null, 'aviso' => 'La factura E (exportación) se autoriza por WSFEX, que todavía no está integrado: se emite sin CAE.'];

        $pv = (int) $c->punto_venta;
        try {
            $afip   = AfipService::forBusiness($b);
            $numero = $afip->getLastVoucher($pv, $afipId) + 1;
            $data   = $this->armarDatos($c, $afipId, $numero);
            $res    = $afip->createVoucher($data);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Log::error('ARCA error', ['comprobante' => $c->id, 'e' => $msg]);
            // Contingencia: sin conexión el comprobante queda pendiente y se reintenta solo.
            if (AfipErrores::esConexion($msg)) return ['estado' => 'pendiente', 'numero' => null, 'error' => $msg, 'explicacion' => AfipErrores::explicar($msg)];
            // El número ya existe en ARCA (se cortó la conexión después de autorizar): se adopta ese CAE.
            if (AfipErrores::esDuplicado($msg) && isset($numero, $afip)) {
                try {
                    $info = $afip->getVoucherInfo($numero, $pv, $afipId);
                    if ($info && ! empty($info['CodAutorizacion']) && (float) ($info['ImpTotal'] ?? 0) === round((float) $c->total, 2)) {
                        return ['estado' => 'aprobado', 'numero' => $numero, 'cae' => $info['CodAutorizacion'], 'cae_vto' => isset($info['FchVto']) ? \Carbon\Carbon::createFromFormat('Ymd', $info['FchVto']) : null, 'respuesta' => $info, 'enviado' => $data ?? null, 'recuperado' => true];
                    }
                } catch (\Throwable $e2) { Log::warning('ARCA recuperación: ' . $e2->getMessage()); }
            }
            return ['estado' => 'rechazado', 'error' => $msg, 'explicacion' => AfipErrores::explicar($msg), 'numero' => null];
        }

        return [
            'estado'    => 'aprobado',
            'numero'    => $numero,
            'cae'       => $res['CAE'] ?? null,
            'cae_vto'   => isset($res['CAEFchVto']) ? \Carbon\Carbon::createFromFormat('Ymd', (string) $res['CAEFchVto']) : null,
            'respuesta' => $res,
            'enviado'   => $data,
        ];
    }

    // Consulta el comprobante en ARCA y compara con lo guardado.
    public function verificar(Comprobante $c, Business $b): array
    {
        if (! $this->configurado($b) || ! $c->afipTipo() || ! $c->numero) return ['ok' => false, 'detalle' => 'Sin certificado o sin número: no hay nada que verificar.'];
        try {
            $info = AfipService::forBusiness($b)->getVoucherInfo((int) $c->numero, (int) $c->punto_venta, (int) $c->afipTipo());
        } catch (\Throwable $e) { return ['ok' => false, 'detalle' => AfipErrores::explicar($e->getMessage())['que']]; }
        if (! $info) return ['ok' => false, 'detalle' => 'ARCA no tiene registrado este comprobante.'];
        $coincide = (string) ($info['CodAutorizacion'] ?? '') === (string) $c->cae && abs((float) ($info['ImpTotal'] ?? 0) - (float) $c->total) < 0.01;
        return ['ok' => $coincide, 'cae' => $info['CodAutorizacion'] ?? null, 'total' => (float) ($info['ImpTotal'] ?? 0), 'fecha' => $info['CbteFch'] ?? null, 'resultado' => $info['Resultado'] ?? null, 'detalle' => $coincide ? 'Coincide con ARCA: CAE y total iguales.' : 'ARCA tiene otro CAE o total para este comprobante. Revisalo.'];
    }

    // Arma el pedido de CAE con todo lo que hoy exige ARCA: condición IVA del receptor, concepto (productos/servicios), tributos, asociados o período.
    public function armarDatos(Comprobante $c, int $afipId, int $numero): array
    {
        $contact = $c->contact;
        $cuit    = $contact?->cuit ? preg_replace('/\D/', '', $contact->cuit) : null;
        $letraC  = in_array($c->tipo, ['FC', 'NCC', 'NDC'], true);
        $porAlicuota = $c->items->groupBy(fn($i) => (string) (float) $i->alicuota_iva);

        $iva = $letraC ? [] : $porAlicuota->filter(fn($items, $al) => (float) $al > 0)->map(fn($items, $al) => [
            'Id'      => self::ALICUOTAS[$al] ?? 5,
            'BaseImp' => round($items->sum(fn($i) => (float) $i->neto), 2),
            'Importe' => round($items->sum(fn($i) => (float) $i->iva), 2),
        ])->values()->all();
        // Ajuste de centavos: el neto gravado informado es la suma de las bases por alícuota y el IVA la suma de sus importes.
        $netoGravado = $letraC ? round((float) $c->neto - (float) $c->descuento, 2) : round(array_sum(array_column($iva, 'BaseImp')), 2);
        $ivaTotal    = $letraC ? 0 : round(array_sum(array_column($iva, 'Importe')), 2);
        $netoExento  = $letraC ? 0 : round($porAlicuota->get('0', collect())->sum(fn($i) => (float) $i->neto), 2);
        $tributos    = $c->impuestos->map(fn($t) => ['Id' => str_starts_with($t->tipo, 'iibb') ? 7 : 99, 'Desc' => str_starts_with($t->tipo, 'iibb') ? 'Percepción IIBB ' . strtoupper(substr($t->tipo, 5)) : $t->tipo, 'BaseImp' => round((float) $t->base, 2), 'Alic' => round((float) $t->alicuota, 2), 'Importe' => round((float) $t->monto, 2)])->values()->all();
        $impTrib     = round(array_sum(array_column($tributos, 'Importe')), 2);
        $total       = round($netoGravado + $netoExento + $ivaTotal + $impTrib, 2);

        // Concepto: 1 productos, 2 servicios, 3 ambos. Con servicios ARCA pide el período y la fecha de vencimiento de pago.
        $servicios = $c->items->filter(fn($i) => $i->product && $i->product->tipo === 'servicio')->count();
        $concepto = $servicios === 0 ? 1 : ($servicios === $c->items->count() ? 2 : 3);

        $data = [
            'CantReg'    => 1,
            'PtoVta'     => (int) $c->punto_venta,
            'CbteTipo'   => $afipId,
            'Concepto'   => $concepto,
            'DocTipo'    => $cuit ? 80 : 99,
            'DocNro'     => $cuit ? (int) $cuit : 0,
            'CbteDesde'  => $numero,
            'CbteHasta'  => $numero,
            'CbteFch'    => $c->fecha->format('Ymd'),
            'ImpTotal'   => $total,
            'ImpTotConc' => 0,
            'ImpNeto'    => $netoGravado,
            'ImpOpEx'    => $netoExento,
            'ImpIVA'     => $ivaTotal,
            'ImpTrib'    => $impTrib,
            'MonId'      => 'PES',
            'MonCotiz'   => 1,
            'CondicionIVAReceptorId' => $cuit ? (self::CONDICION_RECEPTOR[$contact->condicion_iva ?? ''] ?? 5) : 5,
        ];
        if ($iva) $data['Iva'] = $iva;
        if ($tributos) $data['Tributos'] = $tributos;
        if ($concepto !== 1) {
            $data['FchServDesde'] = $c->fecha->copy()->startOfMonth()->format('Ymd');
            $data['FchServHasta'] = $c->fecha->copy()->endOfMonth()->format('Ymd');
            $data['FchVtoPago']   = ($c->fecha_vto ?? $c->fecha)->format('Ymd');
        }
        if (in_array($c->def()['grupo'], ['nc', 'nd'], true)) {
            if ($c->origen && $c->origen->esFiscal() && $c->origen->numero) {
                $data['CbtesAsoc'] = [['Tipo' => $c->origen->afipTipo(), 'PtoVta' => (int) $c->origen->punto_venta, 'Nro' => (int) $c->origen->numero, 'Cuit' => (int) preg_replace('/\D/', '', (string) $c->business->cuit), 'CbteFch' => $c->origen->fecha->format('Ymd')]];
            } else {
                // Sin comprobante asociado ARCA exige el período que ajusta la nota.
                $data['PeriodoAsoc'] = ['FchDesde' => $c->fecha->copy()->startOfMonth()->format('Ymd'), 'FchHasta' => $c->fecha->format('Ymd')];
            }
        }
        if ($c->fce) {
            // FCE MiPyME: CBU del emisor (opcional 2101), sistema de circulación abierta (27 = SCA) y fecha de vencimiento de pago.
            $b = $c->business;
            $data['FchVtoPago'] = ($c->fce_vto_pago ?? $c->fecha_vto ?? $c->fecha)->format('Ymd');
            $data['Opcionales'] = [['Id' => '2101', 'Valor' => preg_replace('/\D/', '', (string) $b->cbu_fce)], ['Id' => '27', 'Valor' => 'SCA']];
            if (in_array($c->def()['grupo'], ['nc', 'nd'], true)) $data['Opcionales'][] = ['Id' => '22', 'Valor' => 'N'];
        }
        return $data;
    }
}
