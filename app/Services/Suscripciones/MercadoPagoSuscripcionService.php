<?php

namespace App\Services\Suscripciones;

use App\Models\PagoSuscripcion;
use App\Models\SistemaConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

// Cobro de suscripciones por MercadoPago con la cuenta de BigSys (no la de cada empresa).
// Sin access token configurado trabaja en modo simulado para poder probar el circuito completo.
class MercadoPagoSuscripcionService
{
    public function configurado(): bool
    {
        return SistemaConfig::mercadoPagoConfigurado();
    }

    // Devuelve la URL a la que mandar al dueño para pagar.
    public function linkDePago(PagoSuscripcion $pago): string
    {
        if (! $this->configurado()) {
            return url("/suscripcion/retorno?pago={$pago->id}&simulado=1&status=approved");
        }

        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $client = new PreferenceClient();
        $pref = $client->create([
            'items' => [[
                'id' => (string) $pago->plan_id,
                'title' => "BigSysWeb · Plan {$pago->plan->name} (" . ($pago->ciclo === 'yearly' ? 'anual' : 'mensual') . ")",
                'quantity' => 1, 'unit_price' => (float) $pago->monto, 'currency_id' => 'ARS',
            ]],
            'external_reference' => (string) $pago->id,
            'payer' => ['email' => $pago->business->email],
            'back_urls' => ['success' => url('/suscripcion/retorno?pago=' . $pago->id), 'failure' => url('/suscripcion/retorno?pago=' . $pago->id), 'pending' => url('/suscripcion/retorno?pago=' . $pago->id)],
            'auto_return' => 'approved',
            'notification_url' => url('/api/webhooks/mercadopago/suscripcion'),
            'statement_descriptor' => 'BIGSYSWEB',
        ]);
        $pago->update(['preference_id' => $pref->id]);
        return $pref->init_point;
    }

    // Consulta un pago en MercadoPago y devuelve [estado, external_reference].
    public function consultarPago(string $paymentId): array
    {
        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $p = (new PaymentClient())->get((int) $paymentId);
        return ['status' => $p->status, 'external_reference' => $p->external_reference, 'amount' => $p->transaction_amount];
    }
}
