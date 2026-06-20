<?php
namespace App\Services\MercadoPago;

use App\Models\Business;
use App\Models\Sale;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Resources\Preference\Item;
use MercadoPago\Resources\Preference\BackUrls;

class MercadoPagoService
{
    public function __construct(private Business $business)
    {
        $settings = $business->mercadopago_settings ?? [];
        MercadoPagoConfig::setAccessToken($settings['access_token'] ?? config('services.mercadopago.access_token'));
    }

    public function createPreference(Sale $sale): array
    {
        $client = new PreferenceClient();

        $items = $sale->items->map(function ($item) {
            return [
                'id'          => (string) $item->product_id,
                'title'       => $item->product->name,
                'quantity'    => $item->quantity,
                'unit_price'  => (float) $item->unit_price,
                'currency_id' => 'ARS',
            ];
        })->toArray();

        $preference = $client->create([
            'items'        => $items,
            'external_reference' => (string) $sale->id,
            'back_urls'    => [
                'success' => config('app.url') . '/api/mercadopago/success',
                'failure' => config('app.url') . '/api/mercadopago/failure',
                'pending' => config('app.url') . '/api/mercadopago/pending',
            ],
            'auto_return'  => 'approved',
            'notification_url' => config('app.url') . '/api/mercadopago/webhook',
            'statement_descriptor' => $this->business->name,
            'expires'      => false,
        ]);

        return [
            'preference_id' => $preference->id,
            'init_point'    => $preference->init_point,
            'sandbox_init_point' => $preference->sandbox_init_point,
        ];
    }

    public function getPayment(string $paymentId): array
    {
        $client  = new PaymentClient();
        $payment = $client->get($paymentId);

        return [
            'id'               => $payment->id,
            'status'           => $payment->status,
            'status_detail'    => $payment->status_detail,
            'amount'           => $payment->transaction_amount,
            'external_reference' => $payment->external_reference,
            'payment_method'   => $payment->payment_method_id,
            'installments'     => $payment->installments,
            'date_approved'    => $payment->date_approved,
        ];
    }

    public static function forBusiness(Business $business): static
    {
        return new static($business);
    }
}
