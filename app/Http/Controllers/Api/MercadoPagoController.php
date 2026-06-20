<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\MercadoPago\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MercadoPagoController extends Controller
{
    public function createPreference(Request $request, Sale $sale): JsonResponse
    {
        $business = $request->user()->business;
        $settings = $business->mercadopago_settings;

        if (! $settings || ! isset($settings['access_token'])) {
            return response()->json([
                'message' => 'Configurá las credenciales de MercadoPago primero.',
            ], 422);
        }

        $service    = MercadoPagoService::forBusiness($business);
        $preference = $service->createPreference($sale->load('items.product'));

        return response()->json($preference);
    }

    public function webhook(Request $request): JsonResponse
    {
        $type = $request->query('type') ?? $request->input('type');
        $id   = $request->query('data_id') ?? $request->input('data', [])['id'] ?? null;

        if ($type === 'payment' && $id) {
            \Log::info('MercadoPago webhook', ['type' => $type, 'id' => $id]);
        }

        return response()->json(['status' => 'ok']);
    }

    public function paymentStatus(Request $request, string $paymentId): JsonResponse
    {
        $business = $request->user()->business;
        $service  = MercadoPagoService::forBusiness($business);
        $payment  = $service->getPayment($paymentId);

        if ($payment['status'] === 'approved' && $payment['external_reference']) {
            $sale = Sale::find($payment['external_reference']);
            if ($sale && $sale->payment_status !== 'paid') {
                $sale->update([
                    'payment_status' => 'paid',
                    'amount_paid'    => $payment['amount'],
                ]);
            }
        }

        return response()->json($payment);
    }

    public function configure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_token' => 'required|string',
            'public_key'   => 'required|string',
        ]);

        $request->user()->business->update([
            'mercadopago_settings' => $data,
        ]);

        return response()->json(['message' => 'MercadoPago configurado correctamente.']);
    }
}
