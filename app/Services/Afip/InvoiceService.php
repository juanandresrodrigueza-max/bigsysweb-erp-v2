<?php
namespace App\Services\Afip;

use App\Models\Business;
use App\Models\Contact;
use App\Models\InvoiceSequence;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    const TIPO_FACTURA_A = 1;
    const TIPO_FACTURA_B = 6;
    const TIPO_FACTURA_C = 11;
    const TIPO_NOTA_CREDITO_A = 3;
    const TIPO_NOTA_CREDITO_B = 8;
    const TIPO_NOTA_CREDITO_C = 13;
    const TIPO_NOTA_DEBITO_A  = 2;
    const TIPO_NOTA_DEBITO_B  = 7;
    const TIPO_NOTA_DEBITO_C  = 12;

    const CONDICION_IVA_RESPONSABLE_INSCRIPTO = 'RI';
    const CONDICION_IVA_MONOTRIBUTO           = 'MO';
    const CONDICION_IVA_CONSUMIDOR_FINAL      = 'CF';
    const CONDICION_IVA_EXENTO                = 'EX';

    const ALICUOTA_IVA = [
        0    => 3,
        2.5  => 9,
        5    => 8,
        10.5 => 4,
        21   => 5,
        27   => 6,
    ];

    public function resolveVoucherType(Business $business, ?Contact $contact): int
    {
        $condicionNegocio  = $business->condicion_iva;
        $condicionContacto = $contact?->condicion_iva ?? self::CONDICION_IVA_CONSUMIDOR_FINAL;

        if ($condicionNegocio === self::CONDICION_IVA_RESPONSABLE_INSCRIPTO) {
            if ($condicionContacto === self::CONDICION_IVA_RESPONSABLE_INSCRIPTO) {
                return self::TIPO_FACTURA_A;
            }
            return self::TIPO_FACTURA_B;
        }

        return self::TIPO_FACTURA_C;
    }

    public function buildVoucherData(Sale $sale, Business $business, int $tipoComprobante): array
    {
        $afip        = AfipService::forBusiness($business);
        $puntoVenta  = (int) $business->afip_punto_venta;
        $lastVoucher = $afip->getLastVoucher($puntoVenta, $tipoComprobante);
        $numero      = $lastVoucher + 1;

        $items    = $sale->items()->with('product')->get();
        $contact  = $sale->contact;

        $conceptos = $items->map(function ($item) {
            $alicuota = 21;
            return [
                'descripcion'        => $item->product->name,
                'cantidad'           => $item->quantity,
                'precio_unitario'    => (float) $item->unit_price,
                'bonificacion'       => (float) $item->discount,
                'alicuota'           => $alicuota,
                'subtotal'           => (float) $item->subtotal,
            ];
        })->toArray();

        $importeNeto = $sale->subtotal / 1.21;
        $importeIva  = $sale->subtotal - $importeNeto;

        return [
            'CantReg'     => 1,
            'PtoVta'      => $puntoVenta,
            'CbteTipo'    => $tipoComprobante,
            'Concepto'    => 1,
            'DocTipo'     => $contact?->cuit ? 80 : 99,
            'DocNro'      => $contact?->cuit ? str_replace('-', '', $contact->cuit) : 0,
            'CbteDesde'   => $numero,
            'CbteHasta'   => $numero,
            'CbteFch'     => now()->format('Ymd'),
            'ImpTotal'    => round((float) $sale->total, 2),
            'ImpTotConc'  => 0,
            'ImpNeto'     => round($importeNeto, 2),
            'ImpOpEx'     => 0,
            'ImpIVA'      => round($importeIva, 2),
            'ImpTrib'     => 0,
            'MonId'       => 'PES',
            'MonCotiz'    => 1,
            'Iva'         => [[
                'Id'      => self::ALICUOTA_IVA[21],
                'BaseImp' => round($importeNeto, 2),
                'Importe' => round($importeIva, 2),
            ]],
        ];
    }

    public function issue(Sale $sale, Business $business): array
    {
        return DB::transaction(function () use ($sale, $business) {
            $contact        = $sale->contact;
            $tipoComprobante = $this->resolveVoucherType($business, $contact);
            $afip           = AfipService::forBusiness($business);
            $voucherData    = $this->buildVoucherData($sale, $business, $tipoComprobante);
            $result         = $afip->createVoucher($voucherData);

            $invoiceNumber = InvoiceSequence::generate($business->id, 'invoice');

            $sale->update([
                'invoice_number' => $invoiceNumber,
                'invoice_type'   => $tipoComprobante,
                'status'         => 'invoiced',
            ]);

            return [
                'cae'            => $result['CAE'],
                'cae_expiry'     => $result['CAEFchVto'],
                'invoice_number' => $invoiceNumber,
                'invoice_type'   => $tipoComprobante,
                'voucher_data'   => $voucherData,
            ];
        });
    }
}
