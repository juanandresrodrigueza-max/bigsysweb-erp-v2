<?php

namespace App\Services\Gastronomia;

use App\Models\Business;
use App\Models\Comanda;
use App\Models\RestoOpinion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fase 56 · Qué dicen los clientes: una encuesta de 10 segundos al pagar (desde el QR de la mesa o el WhatsApp de "pedido listo"),
// el patrón de las quejas (qué día y a qué hora) y, con IA, el resumen de los comentarios.
class OpinionesService
{
    public static function firma(Comanda $c): string { return substr(hash_hmac('sha256', 'opinion-' . $c->id, (string) config('app.key')), 0, 20); }

    public static function link(Business $b, Comanda $c): string
    {
        return url('/m/' . (($b->tienda['slug'] ?? null) ?: $b->slug) . '/opinion?c=' . $c->id . '&f=' . self::firma($c));
    }

    public function guardar(Business $b, Comanda $c, int $puntaje, ?string $comentario, string $canal): RestoOpinion
    {
        return RestoOpinion::withoutGlobalScopes()->updateOrCreate(['comanda_id' => $c->id], ['business_id' => $b->id, 'mesa_id' => $c->mesa_id, 'user_id' => $c->user_id, 'puntaje' => max(1, min(5, $puntaje)), 'comentario' => $comentario ? mb_substr(trim($comentario), 0, 500) : null, 'canal' => $canal]);
    }

    public function resumen(Business $b, int $dias = 30): array
    {
        $ops = RestoOpinion::withoutGlobalScopes()->where('business_id', $b->id)->where('created_at', '>=', now()->subDays($dias))->with('mozo:id,name')->latest()->get();
        if ($ops->isEmpty()) return ['cantidad' => 0, 'promedio' => null, 'distribucion' => [], 'por_mozo' => [], 'patron' => null, 'comentarios' => [], 'ia' => null, 'dias' => $dias];
        $franja = fn($d) => (int) $d->format('G') < 17 ? 'mediodía' : 'noche';
        $malas = $ops->where('puntaje', '<=', 2);
        $patron = null;
        if ($malas->count() >= 3) {
            $g = $malas->groupBy(fn($o) => PronosticoRestoService::DIAS[$o->created_at->isoWeekday()] . ' a la ' . $franja($o->created_at))->sortByDesc(fn($x) => $x->count());
            if ($g->first()->count() >= 2 && $g->first()->count() / $malas->count() >= 0.4) $patron = ['cuando' => $g->keys()->first(), 'cantidad' => $g->first()->count(), 'de' => $malas->count()];
        }
        $out = ['cantidad' => $ops->count(), 'promedio' => round($ops->avg('puntaje'), 1), 'distribucion' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn($p) => [$p => $ops->where('puntaje', $p)->count()])->all(),
            'por_mozo' => $ops->groupBy(fn($o) => $o->mozo?->name ?? 'Sin mozo')->map(fn($g, $m) => ['mozo' => $m, 'cantidad' => $g->count(), 'promedio' => round($g->avg('puntaje'), 1)])->sortBy('promedio')->values()->all(),
            'patron' => $patron, 'comentarios' => $ops->filter(fn($o) => $o->comentario)->take(20)->map(fn($o) => ['fecha' => $o->created_at->format('d/m H:i'), 'puntaje' => $o->puntaje, 'texto' => $o->comentario, 'mozo' => $o->mozo?->name])->values()->all(), 'dias' => $dias];
        $out['ia'] = $this->resumenIa($b, $ops);
        return $out;
    }

    // Resumen de los comentarios con IA, guardado hasta que haya comentarios nuevos (no se le paga a la IA dos veces por lo mismo).
    private function resumenIa(Business $b, $ops): ?array
    {
        $con = $ops->filter(fn($o) => $o->comentario); if ($con->count() < 3 || ! config('services.anthropic.api_key')) return null;
        $clave = md5($con->pluck('id')->implode(',')); $cache = $b->gastronomia_config['opiniones_ia'] ?? null;
        if ($cache && ($cache['clave'] ?? '') === $clave) return $cache['resumen'];
        try {
            $lista = $con->take(80)->map(fn($o) => "[{$o->puntaje}★ {$o->created_at->locale('es')->translatedFormat('l H:i')}" . ($o->mozo ? " · {$o->mozo->name}" : '') . "] {$o->comentario}")->implode("\n");
            $res = Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'), 'max_tokens' => 600,
                'system' => "Sos el encargado de {$b->name}. Leés las opiniones de los clientes del último mes y le contás al dueño lo importante. Devolvé SOLO JSON: {\"puntos\":[\"máximo 4 frases cortas, con la cantidad de veces y cuándo pasa si se repite\"],\"lo_mas_repetido\":\"una frase\",\"elogios\":\"una frase con lo que más destacan\"}. Español rioplatense, sin inventar nada que no esté en los comentarios.",
                'messages' => [['role' => 'user', 'content' => $lista]],
            ]);
            $raw = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $d = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (! is_array($d) || empty($d['puntos'])) return null;
            $r = ['puntos' => array_values(array_filter((array) $d['puntos'], 'is_string')), 'lo_mas_repetido' => (string) ($d['lo_mas_repetido'] ?? ''), 'elogios' => (string) ($d['elogios'] ?? '')];
            $cfg = $b->gastronomia_config ?? []; $cfg['opiniones_ia'] = ['clave' => $clave, 'resumen' => $r, 'fecha' => now()->toDateTimeString()]; $b->forceFill(['gastronomia_config' => $cfg])->save();
            return $r;
        } catch (\Throwable $e) { Log::warning('Opiniones IA: ' . $e->getMessage()); return null; }
    }
}
