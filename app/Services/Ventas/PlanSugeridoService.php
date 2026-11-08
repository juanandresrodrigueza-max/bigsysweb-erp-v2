<?php

namespace App\Services\Ventas;

use App\Models\Business;
use App\Models\Comprobante;
use App\Models\Contact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 57 · Cuando el cliente dice "no llego": el plan de pago que le podés ofrecer, según lo que debe, lo que te compra por mes
// y cómo venía pagando. Cuotas que pueda pagar, interés de la ficha y el mensaje listo para mandar.
class PlanSugeridoService
{
    public function __construct(private RiesgoMoraService $riesgo) {}

    public function sugerir(Business $b, Contact $c): array
    {
        $vencidas = Comprobante::where('contact_id', $c->id)->pendientesCobro()->where('fecha_vto', '<', today())->orderBy('fecha_vto')->get();
        if ($vencidas->isEmpty()) return ['ok' => false, 'aviso' => "{$c->name} no tiene facturas vencidas para refinanciar."];
        $deuda = round($vencidas->sum(fn($f) => (float) $f->saldo), 2);
        $compraMensual = (float) Comprobante::ventas()->emitidos()->facturas()->where('contact_id', $c->id)->where('fecha', '>=', today()->subMonths(6)->toDateString())->sum('total') / 6;
        $r = $this->riesgo->de($c);
        // Cuota que pueda pagar: la mitad de lo que compra por mes (si compra), si no un tercio de la deuda. Entre 1 y 6 cuotas.
        $cuotaObjetivo = $compraMensual > 0 ? $compraMensual * 0.5 : $deuda / 3;
        $n = (int) max(1, min(6, ceil($deuda / max(1, $cuotaObjetivo)))); if ($deuda < 50000) $n = min($n, 2);
        $interesPct = round((float) $c->interes_mora * max(1, $n) / 2, 1); // interés de la ficha, promedio de los meses financiados
        $primera = today()->addDays($r['nivel'] === 'alto' ? 10 : 20);
        $total = round($deuda * (1 + $interesPct / 100), 2); $cuota = round($total / $n, 2);
        $f = fn($v) => '$ ' . number_format($v, 0, ',', '.');
        $texto = "Hola {$c->name}, sabemos que a veces se complica. Para ponerte al día te proponemos: {$f($deuda)} en {$n} cuota" . ($n > 1 ? 's' : '') . " de {$f($cuota)}" . ($interesPct > 0 ? " (con {$interesPct} % de interés)" : '') . ", la primera el " . $primera->format('d/m') . ". Si te sirve, respondé este mensaje y lo dejamos armado. Gracias, {$b->name}.";
        if (config('services.anthropic.api_key')) {
            try {
                $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(20)->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 300,
                    'system' => "Reescribí esta propuesta de plan de pago de {$b->name} para WhatsApp: español rioplatense, voseo, cálido pero claro, máximo 4 líneas, sin emojis, sin cambiar ningún número ni fecha. Devolvé SOLO el texto.", 'messages' => [['role' => 'user', 'content' => $texto . "\nContexto: " . implode('; ', $r['motivos'])]]]);
                $t = trim(collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('')); if ($t !== '' && str_contains($t, (string) $n)) $texto = mb_substr($t, 0, 800);
            } catch (\Throwable $e) { Log::warning('Plan sugerido IA: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'comprobantes' => $vencidas->pluck('id')->all(), 'deuda' => $deuda, 'cuotas' => $n, 'interes_pct' => $interesPct, 'primer_vencimiento' => $primera->toDateString(), 'cuota' => $cuota, 'total' => $total, 'compra_mensual' => round($compraMensual, 2), 'riesgo' => $r, 'texto' => $texto,
            'porque' => ($compraMensual > 0 ? 'Te compra ' . $f($compraMensual) . ' por mes: una cuota de la mitad la puede pagar sin dejar de comprarte. ' : 'No tiene compras recientes: se parte la deuda en tercios. ') . ($r['nivel'] === 'alto' ? 'Riesgo alto: la primera cuota a 10 días.' : 'Primera cuota a 20 días.')];
    }
}
