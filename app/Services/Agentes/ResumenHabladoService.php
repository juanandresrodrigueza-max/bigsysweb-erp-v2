<?php

namespace App\Services\Agentes;

use App\Models\Business;
use App\Services\Avisos\CelularService;

// Fase 107 · El resumen hablado: el parte de la mañana (cómo cerró ayer, qué vence hoy, lo raro del día) más las propuestas que dejó
// Bigcito, en un texto pensado para escucharse: sin emojis ni símbolos, los pesos dichos como se dicen. Lo lee el celular con su
// propia voz (sin servicios externos) y desde la misma pantalla se le repregunta a Bigcito.
class ResumenHabladoService
{
    public function __construct(private CelularService $celular, private AgentesService $agentes) {}

    public function texto(Business $b): array
    {
        $parte = $this->celular->parte($b)['texto'];
        $pend = $this->agentes->pendientes($b);
        $props = $pend->isEmpty() ? 'Hoy no te dejé propuestas.' : ('Te dejé ' . $pend->count() . ' propuesta' . ($pend->count() > 1 ? 's' : '') . ' para aprobar: ' . $pend->take(4)->map(fn($p) => mb_strtolower(mb_substr($p->titulo, 0, 1)) . mb_substr($p->titulo, 1))->implode('; ') . ($pend->count() > 4 ? '; y ' . ($pend->count() - 4) . ' más' : '') . '.');
        $hablado = self::paraVoz("Buen día. " . $parte . "\n" . $props);
        return ['texto' => $parte . "\n\n" . $props, 'hablado' => $hablado, 'propuestas' => $pend->count(), 'fecha' => today()->locale('es')->isoFormat('dddd D [de] MMMM')];
    }

    // De texto de WhatsApp a texto para decir en voz alta.
    public static function paraVoz(string $t): string
    {
        $t = preg_replace('/https?:\/\/\S+/u', '', $t);
        $t = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F900}-\x{1F9FF}\x{FE0F}\x{200D}]/u', '', $t);
        $t = str_replace(['*', '_', '#', '`', '•', '→', '·'], ['', '', '', '', '', ' a ', ','], $t);
        $t = preg_replace_callback('/\$\s?([\d\.]+)(?:,(\d{2}))?/u', function ($m) { $n = (int) str_replace('.', '', $m[1]); $c = isset($m[2]) && (int) $m[2] > 0 ? ' con ' . (int) $m[2] . ' centavos' : ''; return number_format($n, 0, ',', '') . ' pesos' . $c; }, $t);
        $t = preg_replace('/\((\+?-?\d+)% vs ayer\)/u', 'que es un $1 por ciento respecto de ayer', $t);
        $t = preg_replace('/(\d+)%/u', '$1 por ciento', $t);
        $t = preg_replace('/(\d{2})\/(\d{2})/u', '$1 del $2', $t);
        $t = preg_replace('/\s*\n+\s*/u', '. ', trim($t));
        $t = preg_replace('/\.\s*\./u', '.', $t);
        return trim(preg_replace('/\s{2,}/u', ' ', $t));
    }
}
