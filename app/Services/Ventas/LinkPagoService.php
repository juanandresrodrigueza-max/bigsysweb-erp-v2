<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Comprobante;
use App\Services\Comprobantes\CobroService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Link de pago por factura: MercadoPago si la empresa cargó credenciales; si no, un link simulado para probar el circuito.
class LinkPagoService
{
    public function __construct(private CobroService $cobros) {}

    public function crear(Comprobante $c): string
    {
        abort_if(! $c->esFactura() || $c->estado !== 'emitido', 422, 'Solo se genera link para facturas emitidas.');
        if ($c->link_pago) return $c->link_pago;
        $b = $c->business;
        $token = ($b->mercadopago_settings['access_token'] ?? null) ?: config('services.mercadopago.access_token');
        $saldo = (float) $c->saldo > 0 ? (float) $c->saldo : (float) $c->total;
        if ($token) {
            try {
                \MercadoPago\MercadoPagoConfig::setAccessToken($token);
                $pref = (new \MercadoPago\Client\Preference\PreferenceClient())->create([
                    'items' => [['id' => (string) $c->id, 'title' => "{$c->nombreTipo()} {$c->numeroFormateado()} · {$b->name}", 'quantity' => 1, 'unit_price' => round($saldo, 2), 'currency_id' => 'ARS']],
                    'external_reference' => "comp:{$c->id}",
                    'back_urls' => ['success' => $c->urlPublica() . '?pago=ok', 'failure' => $c->urlPublica() . '?pago=error', 'pending' => $c->urlPublica() . '?pago=pendiente'],
                    'auto_return' => 'approved', 'notification_url' => url('/api/mercadopago/webhook'), 'statement_descriptor' => mb_substr($b->name, 0, 22),
                ]);
                $c->forceFill(['link_pago' => $pref->init_point, 'link_pago_id' => $pref->id])->save();
                return $pref->init_point;
            } catch (\Throwable $e) {
                \Log::warning('MercadoPago preferencia: ' . $e->getMessage());
            }
        }
        $link = $c->urlPublica() . '/pagar-simulado';
        $c->forceFill(['link_pago' => $link, 'link_pago_id' => 'simulado'])->save();
        return $link;
    }

    // Registra el cobro cuando el pago llega (webhook real o simulación).
    public function acreditar(Comprobante $c, float $monto, string $referencia, string $medio = 'mercadopago'): ?\App\Models\Cobro
    {
        if ($c->estado !== 'emitido' || (float) $c->saldo <= 0.005) return null;
        return DB::transaction(function () use ($c, $monto, $referencia, $medio) {
            $user = \App\Models\User::where('business_id', $c->business_id)->orderBy('id')->first();
            $yaEstaba = Auth::check();
            if (! $yaEstaba) Auth::setUser($user);
            $monto = min($monto, (float) $c->saldo);
            $cobro = $this->cobros->registrar($c->contact, ['fecha' => today()->toDateString(), 'notas' => "Pago online {$referencia}", 'medios' => [['medio' => $medio, 'monto' => $monto, 'referencia' => $referencia]], 'imputaciones' => [['comprobante_id' => $c->id, 'monto' => $monto]]]);
            \App\Models\Alerta::emitir(['business_id' => $c->business_id, 'business_location_id' => $c->business_location_id, 'modulo' => 'clientes', 'tipo' => 'pago_online', 'modelo' => 'Cobro', 'modelo_id' => $cobro->id, 'severidad' => 'info', 'titulo' => "Pago online recibido: {$c->contact?->name}", 'detalle' => "$ " . number_format($monto, 2, ',', '.') . " por {$c->nombreTipo()} {$c->numeroFormateado()} ({$referencia}).", 'url' => "/comprobantes/{$c->id}"]);
            AuditLog::registrar('crear', $cobro, "Pago online acreditado en {$c->numeroFormateado()}");
            if (! $yaEstaba) Auth::logout();
            return $cobro;
        });
    }
}
