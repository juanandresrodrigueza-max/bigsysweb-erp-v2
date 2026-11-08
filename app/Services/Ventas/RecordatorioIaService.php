<?php

namespace App\Services\Ventas;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Envio;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 57 · El recordatorio escrito para ese cliente: suave con el que siempre paga, firme con el reincidente, con todas sus
// facturas vencidas y, si conviene, la puerta abierta a un plan de pago. Si la IA no está, sale la plantilla de siempre.
class RecordatorioIaService
{
    public function texto(Business $b, Comprobante $c, array $riesgo, string $canal): ?string
    {
        if (! config('services.anthropic.api_key') || ! $c->contact) return null;
        $cli = $c->contact;
        $vencidas = Comprobante::where('contact_id', $cli->id)->pendientesCobro()->where('fecha_vto', '<', today())->orderBy('fecha_vto')->get();
        $porVencer = Comprobante::where('contact_id', $cli->id)->pendientesCobro()->where('fecha_vto', '>=', today())->sum('saldo');
        $avisos = Envio::where('contact_id', $cli->id)->where('tipo', 'recordatorio')->where('created_at', '>=', now()->subDays(45))->count();
        $f = fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
        $datos = "Cliente: {$cli->name}\nCanal: {$canal}\nRiesgo: {$riesgo['nivel']} ({$riesgo['puntaje']}/100). Motivos: " . (implode('; ', $riesgo['motivos']) ?: 'ninguno') . "\nAvisos mandados en los últimos 45 días: {$avisos}\n"
            . ($vencidas->count() ? "Facturas vencidas:\n" . $vencidas->map(fn($x) => "- {$x->nombreTipo()} {$x->numeroFormateado()}: {$f($x->saldo)}, venció hace " . $x->fecha_vto->diffInDays(today()) . ' días')->implode("\n") . "\nTotal vencido: {$f($vencidas->sum('saldo'))}\n" : "La {$c->nombreTipo()} {$c->numeroFormateado()} por {$f($c->saldo)} vence " . ($c->fecha_vto?->isToday() ? 'hoy' : 'el ' . $c->fecha_vto?->format('d/m')) . ".\n")
            . ($porVencer > 0 ? "Además tiene {$f($porVencer)} por vencer.\n" : '') . "Link para ver y pagar: {$c->urlPublica()}\nFirma: {$b->name}" . ($b->phone ? " · {$b->phone}" : '');
        try {
            $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(25)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 400,
                'system' => "Escribís el recordatorio de pago de {$b->name} (Argentina) para un cliente. Español rioplatense, voseo, sin emojis, " . ($canal === 'mail' ? 'máximo 6 líneas' : 'máximo 4 líneas, es un WhatsApp') . ". Reglas de tono: si es el primer aviso o el cliente suele pagar a tiempo, cordial y liviano, dando por hecho que fue un olvido; si ya hubo 2 o más avisos o el riesgo es alto, claro y firme, sin amenazas ni faltas de respeto, pidiendo una fecha concreta de pago; si debe más de una factura, nombralas todas con el total; si el riesgo es alto o debe hace más de 45 días, ofrecé armar un plan de pago en cuotas. Siempre incluí el link tal cual. Devolvé SOLO el texto del mensaje, sin asunto ni comillas.",
                'messages' => [['role' => 'user', 'content' => $datos]],
            ]);
            $t = trim(collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
            return $t !== '' && str_contains($t, $c->urlPublica()) ? mb_substr($t, 0, 1200) : null;
        } catch (\Throwable $e) { Log::warning('Recordatorio IA: ' . $e->getMessage()); return null; }
    }
}
