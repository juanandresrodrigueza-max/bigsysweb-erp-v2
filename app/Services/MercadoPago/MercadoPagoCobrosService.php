<?php

namespace App\Services\MercadoPago;

use App\Models\Business;
use Illuminate\Support\Facades\Http;

// Cobros presenciales con Mercado Pago: QR en el mostrador (orden asociada a la caja QR) y Point (lector de tarjetas).
// Todo por HTTP contra la API de Mercado Pago; configurable por empresa en Configuración → Empresa → Mercado Pago.
class MercadoPagoCobrosService
{
    private const API = 'https://api.mercadopago.com';

    // Lo guardado se lee siempre fresco de la base (el token puede haberse renovado en otra pedida) y se escribe también en la instancia que vino.
    private function settings(Business $b): array { return Business::withoutGlobalScopes()->whereKey($b->id)->first()?->mercadopago_settings ?? $b->mercadopago_settings ?? []; }
    private function guardar(Business $b, array $s): void { Business::withoutGlobalScopes()->whereKey($b->id)->first()?->update(['mercadopago_settings' => $s]); $b->mercadopago_settings = $s; }

    public function config(Business $b): array
    {
        $s = $this->settings($b);
        return ['access_token' => $s['access_token'] ?? config('services.mercadopago.access_token'), 'user_id' => $s['user_id'] ?? null, 'pos_external_id' => $s['pos_external_id'] ?? null, 'point_device_id' => $s['point_device_id'] ?? null,
            'refresh_token' => $s['refresh_token'] ?? null, 'expires_at' => $s['expires_at'] ?? null, 'oauth' => (bool) ($s['oauth'] ?? false), 'nickname' => $s['nickname'] ?? null, 'point_modo' => $s['point_modo'] ?? null];
    }

    // ---- Fase 32.3 · Conectar con un botón (OAuth), el token se renueva solo ----
    public static function appConfigurada(): bool { return (bool) (\App\Models\SistemaConfig::get('mp_app_id') && \App\Models\SistemaConfig::get('mp_app_secret')); }

    public function urlOauth(Business $b): string
    {
        abort_unless(self::appConfigurada(), 422, 'Falta configurar la aplicación de Mercado Pago en el panel de BigSys. Avisale a soporte.');
        $state = \Illuminate\Support\Str::random(32);
        \Illuminate\Support\Facades\Cache::put("conectar:{$state}", ['tipo' => 'mercadopago', 'business_id' => $b->id], now()->addMinutes(20));
        return 'https://auth.mercadopago.com.ar/authorization?' . http_build_query(['client_id' => \App\Models\SistemaConfig::get('mp_app_id'), 'response_type' => 'code', 'platform_id' => 'mp', 'state' => $state, 'redirect_uri' => url('/conectar/mercadopago/volver')]);
    }

    public function volverOauth(Business $b, string $code): array
    {
        $r = Http::asForm()->timeout(15)->post(self::API . '/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => \App\Models\SistemaConfig::get('mp_app_id'), 'client_secret' => \App\Models\SistemaConfig::get('mp_app_secret'), 'code' => $code, 'redirect_uri' => url('/conectar/mercadopago/volver')]);
        if (! $r->ok()) throw \Illuminate\Validation\ValidationException::withMessages(['code' => 'Mercado Pago no aceptó la autorización: ' . mb_substr($r->body(), 0, 200)]);
        $t = $r->json();
        $s = array_merge($this->settings($b), ['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? null, 'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 15552000))->toIso8601String(), 'user_id' => (string) ($t['user_id'] ?? ''), 'oauth' => true]);
        $this->guardar($b, $s);
        try { $nick = Http::withToken($t['access_token'])->timeout(8)->get(self::API . '/users/me')->json('nickname'); if ($nick) { $s['nickname'] = $nick; $this->guardar($b, $s); } } catch (\Throwable) {}
        // Si la cuenta tiene una sola caja QR, queda elegida sola.
        try { $cajas = $this->cajasQr($b); if (count($cajas) === 1 && empty($s['pos_external_id'])) { $s['pos_external_id'] = $cajas[0]['external_id']; $this->guardar($b, $s); } } catch (\Throwable) {}
        \App\Models\AuditLog::registrar('editar', $b, 'Conectó Mercado Pago con un botón');
        return $this->settings($b);
    }

    private function renovarSiVence(Business $b): void
    {
        $c = $this->config($b);
        if (! $c['oauth'] || ! $c['refresh_token'] || ! $c['expires_at'] || now()->addDay()->lt(\Carbon\Carbon::parse($c['expires_at']))) return;
        $r = Http::asForm()->timeout(15)->post(self::API . '/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => \App\Models\SistemaConfig::get('mp_app_id'), 'client_secret' => \App\Models\SistemaConfig::get('mp_app_secret'), 'refresh_token' => $c['refresh_token']]);
        if ($r->ok()) {
            $t = $r->json();
            $this->guardar($b, array_merge($this->settings($b), ['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? $c['refresh_token'], 'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 15552000))->toIso8601String()]));
        }
    }

    public function desconectar(Business $b): void
    {
        $this->guardar($b, array_diff_key($this->settings($b), array_flip(['access_token', 'refresh_token', 'expires_at', 'oauth', 'nickname', 'user_id'])));
    }

    // Las cajas QR de la cuenta (Tu negocio → Sucursales y cajas).
    public function cajasQr(Business $b): array
    {
        $c = $this->config($b);
        $r = $this->http($b)->get(self::API . '/pos', ['user_id' => $c['user_id']]);
        if (! $r->successful()) throw new \RuntimeException('Mercado Pago: ' . ($r->json('message') ?? "HTTP {$r->status()}"));
        return collect($r->json('results') ?? [])->map(fn($p) => ['id' => $p['id'] ?? null, 'external_id' => $p['external_id'] ?? null, 'nombre' => $p['name'] ?? ('Caja ' . ($p['external_id'] ?? '')), 'store_id' => $p['store_id'] ?? null])->filter(fn($p) => $p['external_id'])->values()->all();
    }

    // Las terminales Point de la cuenta, con su modo (PDV = sólo cobra lo que le manda la caja).
    public function terminales(Business $b): array
    {
        $r = $this->http($b)->get(self::API . '/point/integration-api/devices');
        if (! $r->successful()) throw new \RuntimeException('Mercado Pago Point: ' . ($r->json('message') ?? "HTTP {$r->status()}"));
        return collect($r->json('devices') ?? [])->map(fn($d) => ['id' => $d['id'], 'modo' => $d['operating_mode'] ?? null, 'pos_id' => $d['pos_id'] ?? null, 'store_id' => $d['store_id'] ?? null])->values()->all();
    }

    public function elegirTerminal(Business $b, string $deviceId): void
    {
        $r = $this->http($b)->patch(self::API . "/point/integration-api/devices/{$deviceId}", ['operating_mode' => 'PDV']);
        if (! $r->successful()) throw new \RuntimeException('No se pudo poner la terminal en modo PDV: ' . ($r->json('message') ?? "HTTP {$r->status()}"));
        $this->guardar($b, array_merge($this->settings($b), ['point_device_id' => $deviceId, 'point_modo' => 'PDV']));
    }

    // Con qué pagó (débito o crédito, cuotas, tarjeta): para que la factura salga al fondo que corresponde.
    public function detallePago(Business $b, string $pagoId): array
    {
        try {
            $p = $this->http($b)->get(self::API . "/v1/payments/{$pagoId}")->json() ?? [];
            $tipo = $p['payment_type_id'] ?? '';
            return ['tarjeta' => $tipo === 'debit_card' ? 'Débito' : ($tipo === 'credit_card' ? 'Crédito' : ucfirst(str_replace('_', ' ', $tipo ?: 'Mercado Pago'))), 'marca' => $p['payment_method_id'] ?? null, 'cuotas' => (int) ($p['installments'] ?? 1), 'es_tarjeta' => in_array($tipo, ['debit_card', 'credit_card', 'prepaid_card'], true)];
        } catch (\Throwable) { return ['tarjeta' => 'Mercado Pago', 'marca' => null, 'cuotas' => 1, 'es_tarjeta' => false]; }
    }

    public function qrConfigurado(Business $b): bool { $c = $this->config($b); return (bool) ($c['access_token'] && $c['user_id'] && $c['pos_external_id']); }
    public function pointConfigurado(Business $b): bool { $c = $this->config($b); return (bool) ($c['access_token'] && $c['point_device_id']); }

    private function http(Business $b) { $this->renovarSiVence($b); return Http::withToken($this->config($b)['access_token'])->timeout(12)->acceptJson(); }

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
            'FINISHED' => ['estado' => 'pagado', 'pago_id' => (string) ($r->json('payment.id') ?? ''), 'monto' => (float) ($r->json('amount') ?? 0) / 100] + ($r->json('payment.id') ? $this->detallePago($b, (string) $r->json('payment.id')) : []),
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
