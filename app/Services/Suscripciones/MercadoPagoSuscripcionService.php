<?php

namespace App\Services\Suscripciones;

use App\Models\PagoSuscripcion;
use App\Models\SistemaConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Client\Order\OrderClient;
use MercadoPago\Client\Preference\PreferenceClient;
use Illuminate\Support\Facades\Http;
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
    // Fase 117.1: Checkout Pro por la API de Orders (la de Preferences está en retirada). Si Orders falla, cae a Preferences.
    public function linkDePago(PagoSuscripcion $pago): string
    {
        if (! $this->configurado()) {
            return url("/suscripcion/retorno?pago={$pago->id}&simulado=1&status=approved");
        }

        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $titulo = "BigSysWeb · Plan {$pago->plan->name} (" . ($pago->ciclo === 'yearly' ? 'anual' : 'mensual') . ")";
        $vuelta = url('/suscripcion/retorno?pago=' . $pago->id);
        try {
            $opts = new \MercadoPago\Client\Common\RequestOptions();
            $opts->setCustomHeaders(['X-Idempotency-Key: pago-suscripcion-' . $pago->id . '-' . $pago->updated_at?->timestamp]);
            $orden = (new OrderClient())->create([
                'type' => 'online',
                'processing_mode' => 'manual',
                'total_amount' => number_format((float) $pago->monto, 2, '.', ''),
                'external_reference' => (string) $pago->id,
                'description' => $titulo,
                'expiration_time' => 'P3D',
                'items' => [['external_code' => 'plan-' . $pago->plan_id, 'title' => $titulo, 'quantity' => 1, 'unit_price' => number_format((float) $pago->monto, 2, '.', '')]],
                'payer' => ['email' => $pago->business->email],
                'config' => [
                    'statement_descriptor' => 'BIGSYSWEB',
                    'online' => ['success_url' => $vuelta, 'pending_url' => $vuelta, 'failure_url' => $vuelta, 'auto_return' => 'approved'],
                ],
            ], $opts);
            if (! empty($orden->checkout_url)) {
                $pago->update(['preference_id' => (string) $orden->id]);
                return $orden->checkout_url;
            }
            report(new \RuntimeException('Mercado Pago Orders devolvió la orden sin checkout_url (' . ($orden->id ?? '?') . ')'));
        } catch (\Throwable $e) {
            report($e);
        }

        $client = new PreferenceClient();
        $pref = $client->create([
            'items' => [[
                'id' => (string) $pago->plan_id,
                'title' => $titulo,
                'quantity' => 1, 'unit_price' => (float) $pago->monto, 'currency_id' => 'ARS',
            ]],
            'external_reference' => (string) $pago->id,
            'payer' => ['email' => $pago->business->email],
            'back_urls' => ['success' => $vuelta, 'failure' => $vuelta, 'pending' => $vuelta],
            'auto_return' => 'approved',
            'notification_url' => url('/api/webhooks/mercadopago/suscripcion'),
            'statement_descriptor' => 'BIGSYSWEB',
        ]);
        $pago->update(['preference_id' => $pref->id]);
        return $pref->init_point;
    }

    public static function esOrden(?string $id): bool { return $id !== null && str_starts_with($id, 'ORD'); }

    // Consulta una orden (API de Orders) y la resume: estado normalizado como el de un pago, referencia y el id del pago aprobado.
    public function consultarOrden(string $orderId): array
    {
        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $o = (new OrderClient())->get($orderId);
        $pagos = is_object($o->transactions ?? null) ? ($o->transactions->payments ?? []) : (($o->transactions['payments'] ?? []) ?: []);
        $pagoId = null; $pagoEstado = null;
        foreach ($pagos as $pg) {
            $pg = is_array($pg) ? (object) $pg : $pg;
            if (($pg->status ?? '') === 'processed' || ($pg->status ?? '') === 'approved') { $pagoId = (string) $pg->id; $pagoEstado = 'approved'; break; }
            $pagoId = $pagoId ?: (string) ($pg->id ?? ''); $pagoEstado = $pagoEstado ?: ($pg->status ?? null);
        }
        $estado = match ((string) $o->status) {
            'processed' => 'approved',
            'failed', 'canceled', 'cancelled', 'expired', 'refunded' => 'rejected',
            default => 'pending',
        };
        return ['status' => $estado, 'estado_mp' => (string) $o->status, 'external_reference' => $o->external_reference, 'amount' => (float) ($o->total_paid_amount ?: $o->total_amount), 'pago_id' => $pagoId ?: null, 'pago_estado' => $pagoEstado];
    }

    // Consulta un pago en MercadoPago y devuelve [estado, external_reference].
    public function consultarPago(string $paymentId): array
    {
        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $p = (new PaymentClient())->get((int) $paymentId);
        return ['status' => $p->status, 'external_reference' => $p->external_reference, 'amount' => $p->transaction_amount];
    }

    // ── Fase 117 · Débito automático (preapproval de Mercado Pago) ─────────────────────────────────────────────────────────────
    // El dueño autoriza una vez con tarjeta y Mercado Pago cobra solo cada mes (o cada año). Sin credenciales, modo simulado.

    public static function referenciaDebito(int $businessId): string { return "debito-{$businessId}"; }

    // Crea la suscripción en Mercado Pago y devuelve a dónde mandar al dueño a autorizar la tarjeta.
    public function crearDebito(\App\Models\Business $b, \App\Models\Plan $plan, string $ciclo, string $emailPagador): array
    {
        $monto = (float) ($ciclo === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
        if (! $this->configurado()) {
            $id = 'SIMULADO-DEB-' . $b->id . '-' . time();
            return ['id' => $id, 'init_point' => url("/suscripcion/retorno-debito?preapproval_id={$id}&simulado=1")];
        }
        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $pre = (new PreApprovalClient())->create([
            'reason' => "BigSysWeb · Plan {$plan->name} (" . ($ciclo === 'yearly' ? 'anual' : 'mensual') . ')',
            'external_reference' => self::referenciaDebito($b->id),
            'payer_email' => $emailPagador,
            'back_url' => url('/suscripcion/retorno-debito'),
            'status' => 'pending',
            'auto_recurring' => ['frequency' => $ciclo === 'yearly' ? 12 : 1, 'frequency_type' => 'months', 'transaction_amount' => $monto, 'currency_id' => 'ARS'],
        ]);
        return ['id' => (string) $pre->id, 'init_point' => $pre->init_point];
    }

    // Estado de un débito: authorized (cobrando), pending (sin autorizar), paused, cancelled.
    public function consultarDebito(string $preapprovalId): array
    {
        if (str_starts_with($preapprovalId, 'SIMULADO-')) return ['id' => $preapprovalId, 'status' => 'authorized', 'external_reference' => null, 'next_payment_date' => now()->addMonth()->toDateString(), 'monto' => null];
        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        $p = (new PreApprovalClient())->get($preapprovalId);
        return ['id' => (string) $p->id, 'status' => (string) $p->status, 'external_reference' => $p->external_reference ?? null, 'next_payment_date' => $p->next_payment_date ?? null, 'monto' => $p->auto_recurring->transaction_amount ?? null, 'payer_email' => $p->payer_email ?? null];
    }

    public function cancelarDebito(string $preapprovalId): void
    {
        if (str_starts_with($preapprovalId, 'SIMULADO-') || ! $this->configurado()) return;
        MercadoPagoConfig::setAccessToken(SistemaConfig::get('mp_access_token'));
        (new PreApprovalClient())->update($preapprovalId, ['status' => 'cancelled']);
    }

    // Un cobro recurrente avisado por el webhook subscription_authorized_payment.
    public function pagoAutorizado(string $id): array
    {
        $r = Http::withToken(SistemaConfig::get('mp_access_token'))->timeout(15)->get("https://api.mercadopago.com/authorized_payments/{$id}");
        if (! $r->successful()) throw new \RuntimeException("Mercado Pago respondió {$r->status()} al consultar el cobro {$id}.");
        return self::normalizarCobro($r->json());
    }

    // Todos los cobros de un débito (para reconciliar si se perdió un webhook).
    public function pagosDelDebito(string $preapprovalId): array
    {
        if (str_starts_with($preapprovalId, 'SIMULADO-') || ! $this->configurado()) return [];
        $r = Http::withToken(SistemaConfig::get('mp_access_token'))->timeout(15)->get('https://api.mercadopago.com/authorized_payments/search', ['preapproval_id' => $preapprovalId, 'limit' => 50]);
        if (! $r->successful()) return [];
        return array_map([self::class, 'normalizarCobro'], (array) ($r->json('results') ?? []));
    }

    private static function normalizarCobro(array $a): array
    {
        return ['id' => (string) ($a['id'] ?? ''), 'preapproval_id' => (string) ($a['preapproval_id'] ?? ''), 'estado' => (string) ($a['status'] ?? ''), 'pago_id' => isset($a['payment']['id']) ? (string) $a['payment']['id'] : null,
            'pago_estado' => (string) ($a['payment']['status'] ?? ''), 'monto' => (float) ($a['transaction_amount'] ?? 0), 'fecha' => $a['date_created'] ?? ($a['debit_date'] ?? null), 'external_reference' => $a['external_reference'] ?? null];
    }
}
