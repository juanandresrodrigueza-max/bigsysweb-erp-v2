<?php

namespace App\Services\Comprobantes;

use App\Models\Business;
use App\Models\Comprobante;
use App\Services\Afip\AfipService;
use Illuminate\Support\Facades\Log;

// Pide el CAE a AFIP. Sin certificado cargado trabaja en modo simulado para poder probar.
class AfipEmisor
{
    private const ALICUOTAS = ['0' => 3, '2.5' => 9, '5' => 8, '10.5' => 4, '21' => 5, '27' => 6];

    public function configurado(Business $b): bool
    {
        return (bool) ($b->cuit && $b->afip_cert_path && $b->afip_key_path);
    }

    public function emitir(Comprobante $c, Business $b): array
    {
        $afipId = $c->afipTipo();
        if (! $afipId) {
            return ['estado' => 'no_aplica', 'numero' => null];
        }

        if (! $this->configurado($b)) {
            return ['estado' => 'simulado', 'numero' => null, 'cae' => null, 'cae_vto' => null];
        }

        $afip   = AfipService::forBusiness($b);
        $pv     = (int) $c->punto_venta;
        $numero = $afip->getLastVoucher($pv, $afipId) + 1;
        $data   = $this->armarDatos($c, $afipId, $numero);

        try {
            $res = $afip->createVoucher($data);
        } catch (\Throwable $e) {
            Log::error('AFIP error', ['comprobante' => $c->id, 'e' => $e->getMessage()]);
            return ['estado' => 'rechazado', 'error' => $e->getMessage(), 'numero' => null];
        }

        return [
            'estado'    => 'aprobado',
            'numero'    => $numero,
            'cae'       => $res['CAE'] ?? null,
            'cae_vto'   => isset($res['CAEFchVto']) ? \Carbon\Carbon::createFromFormat('Ymd', $res['CAEFchVto']) : null,
            'respuesta' => $res,
            'enviado'   => $data,
        ];
    }

    private function armarDatos(Comprobante $c, int $afipId, int $numero): array
    {
        $contact = $c->contact;
        $cuit    = $contact?->cuit ? preg_replace('/\D/', '', $contact->cuit) : null;
        $porAlicuota = $c->items->groupBy(fn($i) => (string) (float) $i->alicuota_iva);

        $iva = $porAlicuota->filter(fn($items, $al) => (float) $al > 0)->map(fn($items, $al) => [
            'Id'      => self::ALICUOTAS[$al] ?? 5,
            'BaseImp' => round($items->sum(fn($i) => (float) $i->neto), 2),
            'Importe' => round($items->sum(fn($i) => (float) $i->iva), 2),
        ])->values()->all();

        $netoGravado = round($porAlicuota->filter(fn($i, $al) => (float) $al > 0)->flatten(1)->sum(fn($i) => (float) $i->neto), 2);
        $netoExento  = round($porAlicuota->get('0', collect())->sum(fn($i) => (float) $i->neto), 2);

        $data = [
            'CantReg'    => 1,
            'PtoVta'     => (int) $c->punto_venta,
            'CbteTipo'   => $afipId,
            'Concepto'   => 1,
            'DocTipo'    => $cuit ? 80 : 99,
            'DocNro'     => $cuit ? (int) $cuit : 0,
            'CbteDesde'  => $numero,
            'CbteHasta'  => $numero,
            'CbteFch'    => $c->fecha->format('Ymd'),
            'ImpTotal'   => round((float) $c->total, 2),
            'ImpTotConc' => 0,
            'ImpNeto'    => $netoGravado,
            'ImpOpEx'    => $netoExento,
            'ImpIVA'     => round((float) $c->iva, 2),
            'ImpTrib'    => round((float) $c->percepciones, 2),
            'MonId'      => 'PES',
            'MonCotiz'   => 1,
        ];
        if ($iva) {
            $data['Iva'] = $iva;
        }
        if ($c->origen && $c->origen->esFiscal() && in_array($c->def()['grupo'], ['nc', 'nd'], true)) {
            $data['CbtesAsoc'] = [['Tipo' => $c->origen->afipTipo(), 'PtoVta' => (int) $c->origen->punto_venta, 'Nro' => (int) $c->origen->numero]];
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
