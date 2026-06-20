<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Sale;
use App\Services\Afip\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService) {}

    public function preview(Request $request, Sale $sale): JsonResponse
    {
        $business = $request->user()->business;
        $contact  = $sale->contact;

        $tipoComprobante = $this->invoiceService->resolveVoucherType($business, $contact);

        $tipos = [
            1  => 'Factura A',
            6  => 'Factura B',
            11 => 'Factura C',
            3  => 'Nota de Crédito A',
            8  => 'Nota de Crédito B',
            13 => 'Nota de Crédito C',
        ];

        return response()->json([
            'sale'             => $sale->load(['items.product', 'contact']),
            'business'         => [
                'razon_social'    => $business->razon_social,
                'cuit'            => $business->cuit,
                'condicion_iva'   => $business->condicion_iva,
                'afip_punto_venta'=> $business->afip_punto_venta,
                'address'         => $business->address ?? null,
            ],
            'contact'          => $contact ? [
                'name'          => $contact->name,
                'cuit'          => $contact->cuit,
                'condicion_iva' => $contact->condicion_iva,
                'address'       => $contact->address,
            ] : null,
            'invoice_type'     => $tipoComprobante,
            'invoice_type_name'=> $tipos[$tipoComprobante] ?? 'Desconocido',
            'items'            => $sale->items->map(fn($i) => [
                'description' => $i->product->name,
                'quantity'    => $i->quantity,
                'unit_price'  => $i->unit_price,
                'discount'    => $i->discount,
                'subtotal'    => $i->subtotal,
                'iva'         => '21%',
            ]),
            'totals'           => [
                'subtotal' => $sale->subtotal,
                'discount' => $sale->discount,
                'tax'      => $sale->tax,
                'total'    => $sale->total,
            ],
            'afip_ready'       => (bool) ($business->cuit && $business->afip_cert_path && $business->afip_key_path && $business->afip_punto_venta),
        ]);
    }

    public function issue(Request $request, Sale $sale): JsonResponse
    {
        $business = $request->user()->business;

        if (! $business->cuit || ! $business->afip_cert_path || ! $business->afip_key_path) {
            return response()->json([
                'message' => 'Configurá los datos AFIP del negocio antes de facturar.',
                'missing' => array_filter([
                    'cuit'            => ! $business->cuit,
                    'afip_cert_path'  => ! $business->afip_cert_path,
                    'afip_key_path'   => ! $business->afip_key_path,
                    'afip_punto_venta'=> ! $business->afip_punto_venta,
                ]),
            ], 422);
        }

        if ($sale->invoice_number) {
            return response()->json(['message' => 'Esta venta ya tiene comprobante emitido.'], 422);
        }

        $result = $this->invoiceService->issue($sale, $business);

        return response()->json($result, 201);
    }

    public function uploadCertificate(Request $request): JsonResponse
    {
        $request->validate([
            'cert' => 'required|file|mimes:crt,pem,cer|max:50',
            'key'  => 'required|file|mimes:key,pem|max:50',
        ]);

        $business = $request->user()->business;

        $certPath = $request->file('cert')->storeAs(
            "afip/{$business->id}", 'cert.crt', 'local'
        );
        $keyPath = $request->file('key')->storeAs(
            "afip/{$business->id}", 'private.key', 'local'
        );

        $business->update([
            'afip_cert_path' => $certPath,
            'afip_key_path'  => $keyPath,
        ]);

        return response()->json(['message' => 'Certificados cargados correctamente.']);
    }
}
