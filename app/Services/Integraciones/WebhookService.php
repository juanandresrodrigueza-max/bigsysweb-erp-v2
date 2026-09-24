<?php

namespace App\Services\Integraciones;

use App\Models\Webhook;
use App\Models\WebhookEntrega;
use Illuminate\Support\Facades\Http;

// Dispara los webhooks de la empresa. POST JSON firmado con HMAC-SHA256 en el header X-BigSys-Firma.
class WebhookService
{
    public function disparar(int $businessId, string $evento, array $payload): void
    {
        $hooks = Webhook::withoutGlobalScopes()->where('business_id', $businessId)->where('activo', true)->get()->filter(fn($h) => in_array($evento, $h->eventos ?? [], true) || in_array('*', $h->eventos ?? [], true));
        foreach ($hooks as $h) $this->enviar($h, $evento, $payload);
    }

    public function enviar(Webhook $h, string $evento, array $payload): WebhookEntrega
    {
        $cuerpo = ['evento' => $evento, 'empresa_id' => $h->business_id, 'fecha' => now()->toIso8601String(), 'datos' => $payload];
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        $t = microtime(true); $status = null; $resp = null;
        try {
            $r = Http::timeout(6)->withHeaders(['X-BigSys-Evento' => $evento, 'X-BigSys-Firma' => hash_hmac('sha256', $json, $h->secreto), 'Content-Type' => 'application/json'])->withBody($json, 'application/json')->post($h->url);
            $status = $r->status(); $resp = mb_substr($r->body(), 0, 500);
        } catch (\Throwable $e) { $resp = mb_substr($e->getMessage(), 0, 500); }
        $ok = $status !== null && $status < 400;
        $h->forceFill(['fallos' => $ok ? 0 : $h->fallos + 1, 'ultimo_envio_en' => now(), 'activo' => $ok || $h->fallos + 1 < 20 ? $h->activo : false])->save();
        $e = WebhookEntrega::create(['webhook_id' => $h->id, 'evento' => $evento, 'payload' => $cuerpo, 'status' => $status, 'respuesta' => $resp, 'ms' => (int) ((microtime(true) - $t) * 1000)]);
        WebhookEntrega::where('webhook_id', $h->id)->orderByDesc('id')->skip(50)->limit(1000)->delete();
        return $e;
    }

    public static function comprobante(\App\Models\Comprobante $c): array
    {
        return ['id' => $c->id, 'tipo' => $c->tipo, 'nombre' => $c->nombreTipo(), 'numero' => $c->numeroFormateado(), 'fecha' => $c->fecha?->toDateString(), 'cliente' => $c->contact ? ['id' => $c->contact->id, 'nombre' => $c->contact->name, 'cuit' => $c->contact->cuit] : null, 'neto' => (float) $c->neto, 'iva' => (float) $c->iva, 'total' => (float) $c->total, 'saldo' => (float) $c->saldo, 'estado' => $c->estado, 'cae' => $c->cae, 'items' => $c->items->map(fn($i) => ['product_id' => $i->product_id, 'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'precio_unit' => (float) $i->precio_unit, 'total' => (float) $i->total])->all()];
    }
}
