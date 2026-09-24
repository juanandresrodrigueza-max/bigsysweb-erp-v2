<?php

namespace App\Services\MercadoPago;

use App\Models\Business;
use Illuminate\Support\Facades\Http;

// Cobros presenciales con Mercado Pago: QR en el mostrador (orden asociada a la caja QR) y Point (lector de tarjetas).
// Todo por HTTP contra la API de Mercado Pago; configurable por empresa en Configuración → Empresa → Mercado Pago.
class MercadoPagoCobrosService
{
    private const API = 'https://api.mercadopago.com';

    public function config(Business $b): array
    {
        $s = $b->mercadopago_settings ?? [];
        return ['access_token' => $s['access_token'] ?? config('services.mercadopago.access_token'), 'user_id' => $s['user_id'] ?? null, 'pos_external_id' => $s['pos_external_id'] ?? null, 'point_device_id' => $s['point_device_id'] ?? null];
    }

    public function qrConfigurado(Business $b): bool { $c = $this->config($b); return (bool) ($c['access_token'] && $c['user_id'] && $c['pos_external_id']); }
    public function pointConfigurado(Business $b): bool { $c = $this->config($b); return (bool) ($c['access_token'] && $c['point_device_id']); }

    private function http(Business $b) { return Http::withToken($this->config($b)['access_token'])->timeout(12)->acceptJson(); }

    // QR: crea la orden en la caja QR de la empresa; el cliente escanea el QR fijo del mostrador y paga.
    public function iniciarQr(Business $b, float $monto, string $referencia, string $titulo = 'Venta de mostrador'): array
    {
        abort_unless($this->qrConfigurado($b), 422, 'Configurá el QR de Mercado Pago (token, user id y caja) en Configuración → Empresa.');
        $c = $this->config($b);
        $r = $this->http($b)->put(self::API . "/instore/orders/qr/seller/collectors/{$c['user_id']}/pos/{$c['pos_external_id']}/qrs", [
            'external_reference' => $referencia, 'title' => $titulo, 'description' => $titulo, 'total_amount' => round($monto, 2),
            'items' => [['title' => $titulo, 'unit_price' => round($monto, 2), 'quantity' => 1, 'unit_measure' => 'unit', 'total_amount' => round($monto, 2)]],
            'notification_url' => url('/api/mercadopago/webhook'),
        ]);
        if (! $r->successful()) throw new \RuntimeException('Mercado Pago QR: ' . ($r->json('message') ?? "HTTP {$r->status()}"));
        return ['tipo' => 'qr', 'id' => $referencia, 'qr_data' => $r->json('qr_data'), 'in_store_order_id' => $r->json('in_store_order_id')];
    }

    // Estado de una orden QR por referencia: pagado | pendiente | cancelado (+ id de pago).
    public function estadoQr(Business $b, string $referencia): array
    {
        $r = $this->http($b)->get(self::API . '/merchant_orders/search', ['external_reference' => $referencia, 'sort' => 'date_created', 'criteria' => 'desc']);
        $orden = $r->json('elements.0') ?? null;
        if (! $orden) return ['estado' => 'pendiente'];
        $pagado = collect($orden['payments'] ?? [])->where('status', 'approved')->sum('transaction_amount');
        if (($orden['order_status'] ?? '') === 'paid' || ($orden['status'] ?? '') === 'closed' && $pagado > 0) return ['estado' => 'pagado', 'pago_id' => (string) (collect($orden['payments'] ?? [])->firstWhere('status', 'approved')['id'] ?? ''), 'monto' => (float) $pagado];
        if (in_array($orden['order_status'] ?? '', ['expired', 'cancelled'], true)) return ['estado' => 'cancelado'];
        return ['estado' => 'pendiente'];
    }

    // Point: manda el importe al lector; el cliente pasa la tarjeta.
    public function iniciarPoint(Business $b, float $monto, string $referencia): array
    {
        abort_unless($this->pointConfigurado($b), 422, 'Configurá el Point de Mercado Pago (token y dispositivo) en Configuración → Empresa.');
        $c = $this->config($b);
        $r = $this->http($b)->post(self::API . "/point/integration-api/devices/{$c['point_device_id']}/payment-intents", [
            'amount' => (int) round($monto * 100), // en centavos
            'additional_info' => ['external_reference' => $referencia, 'print_on_terminal' => true],
        ]);
        if (! $r->successful()) throw new \RuntimeException('Mercado Pago Point: ' . ($r->json('message') ?? "HTTP {$r->status()}"));
        return ['tipo' => 'point', 'id' => (string) $r->json('id'), 'device' => $c['point_device_id']];
    }

    public function estadoPoint(Business $b, string $intentId): array
    {
        $r = $this->http($b)->get(self::API . "/point/integration-api/payment-intents/{$intentId}");
        $st = $r->json('state') ?? '';
        return match ($st) {
            'FINISHED' => ['estado' => 'pagado', 'pago_id' => (string) ($r->json('payment.id') ?? ''), 'monto' => (float) ($r->json('amount') ?? 0) / 100],
            'CANCELED', 'ERROR', 'ABANDONED' => ['estado' => 'cancelado'],
            default => ['estado' => 'pendiente'],
        };
    }

    public function cancelarPoint(Business $b, string $intentId): void
    {
        $c = $this->config($b);
        $this->http($b)->delete(self::API . "/point/integration-api/devices/{$c['point_device_id']}/payment-intents/{$intentId}");
    }
}
