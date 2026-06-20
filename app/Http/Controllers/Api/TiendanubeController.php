<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tiendanube\TiendanubeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TiendanubeController extends Controller
{
    public function configure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_token' => 'required|string',
            'store_id'     => 'required|string',
        ]);

        $request->user()->business->update([
            'tiendanube_settings' => $data,
        ]);

        return response()->json(['message' => 'Tiendanube configurado correctamente.']);
    }

    public function syncOrders(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $service  = TiendanubeService::forBusiness($business);
        $orders   = $service->getOrders();
        $imported = 0;

        foreach ($orders as $order) {
            if ($service->importOrder($order)) {
                $imported++;
            }
        }

        return response()->json([
            'message'  => "Se importaron {$imported} pedidos de Tiendanube.",
            'imported' => $imported,
            'total'    => count($orders),
        ]);
    }

    public function syncStock(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $service  = TiendanubeService::forBusiness($business);

        $products = \App\Models\Product::whereNotNull('tiendanube_variant_id')->get();
        $synced   = 0;

        foreach ($products as $product) {
            if ($service->syncProductStock($product)) {
                $synced++;
            }
        }

        return response()->json([
            'message' => "Se sincronizó el stock de {$synced} productos.",
            'synced'  => $synced,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $order = $request->input('order');

        Log::info('Tiendanube webhook', ['event' => $event]);

        if ($event === 'orders/paid' && $order) {
            // proceso async via queue en producción
        }

        return response()->json(['status' => 'ok']);
    }
}
