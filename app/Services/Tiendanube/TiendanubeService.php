<?php
namespace App\Services\Tiendanube;

use App\Models\Business;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TiendanubeService
{
    private string $baseUrl;
    private string $accessToken;
    private string $storeId;

    public function __construct(private Business $business)
    {
        $settings          = $business->tiendanube_settings ?? [];
        $this->accessToken = $settings['access_token'] ?? '';
        $this->storeId     = $settings['store_id'] ?? '';
        $this->baseUrl     = "https://api.tiendanube.com/v1/{$this->storeId}";
    }

    private function http()
    {
        return Http::withHeaders([
            'Authentication' => "bearer {$this->accessToken}",
            'User-Agent'     => 'BigSysWeb ERP (soporte@bigsysweb.tech)',
            'Content-Type'   => 'application/json',
        ]);
    }

    public function getOrders(int $page = 1): array
    {
        $response = $this->http()->get("{$this->baseUrl}/orders", [
            'page'     => $page,
            'per_page' => 50,
        ]);

        return $response->json() ?? [];
    }

    public function getOrder(string $orderId): array
    {
        return $this->http()->get("{$this->baseUrl}/orders/{$orderId}")->json() ?? [];
    }

    public function getProducts(int $page = 1): array
    {
        $response = $this->http()->get("{$this->baseUrl}/products", [
            'page'     => $page,
            'per_page' => 50,
        ]);

        return $response->json() ?? [];
    }

    public function updateStock(string $variantId, int $stock): bool
    {
        $response = $this->http()->put("{$this->baseUrl}/products/variants/{$variantId}", [
            'stock' => $stock,
        ]);

        return $response->successful();
    }

    public function syncProductStock(Product $product): bool
    {
        $settings  = $product->tiendanube_variant_id ?? null;
        if (! $settings) return false;

        return $this->updateStock($settings, $product->stock);
    }

    public function importOrder(array $order): ?Sale
    {
        try {
            $contactData = $order['customer'] ?? [];
            $contact = Contact::firstOrCreate(
                ['email' => $contactData['email'] ?? null, 'business_id' => $this->business->id],
                [
                    'business_id' => $this->business->id,
                    'type'        => 'customer',
                    'name'        => trim(($contactData['name'] ?? '') . ' ' . ($contactData['last_name'] ?? '')),
                    'email'       => $contactData['email'] ?? null,
                    'phone'       => $contactData['phone'] ?? null,
                ]
            );

            $sale = Sale::create([
                'business_id' => $this->business->id,
                'user_id'     => $this->business->owner_id,
                'contact_id'  => $contact->id,
                'status'      => 'confirmed',
                'payment_status' => $order['payment_status'] === 'paid' ? 'paid' : 'pending',
                'subtotal'    => $order['subtotal'] ?? 0,
                'total'       => $order['total'] ?? 0,
                'discount'    => $order['discount'] ?? 0,
                'tax'         => $order['taxes'] ?? 0,
                'notes'       => "Tiendanube Order #{$order['number']}",
            ]);

            foreach ($order['products'] ?? [] as $item) {
                $product = Product::where('business_id', $this->business->id)
                    ->where('sku', $item['sku'] ?? null)
                    ->first();

                $sale->items()->create([
                    'product_id' => $product?->id ?? null,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['price'],
                    'discount'   => 0,
                    'subtotal'   => $item['price'] * $item['quantity'],
                ]);
            }

            return $sale;
        } catch (\Exception $e) {
            Log::error('Tiendanube import error', ['error' => $e->getMessage(), 'order' => $order['id'] ?? null]);
            return null;
        }
    }

    public static function forBusiness(Business $business): static
    {
        return new static($business);
    }
}
