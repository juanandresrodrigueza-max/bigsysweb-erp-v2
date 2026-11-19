<?php

namespace App\Services\IA;

use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

// Fase 40.3: los audios los transcribe OpenAI o Gemini (Claude no lee audio), con la clave que carga la empresa;
// las fotos van directo a Claude junto con la pregunta.
class VozFotoService
{
    public const PROVEEDORES = ['openai' => 'OpenAI (Whisper)', 'gemini' => 'Google Gemini'];

    public const VOCES = ['openai' => ['coral', 'nova', 'shimmer', 'sage', 'alloy', 'ash', 'echo', 'onyx'], 'gemini' => ['Kore', 'Aoede', 'Leda', 'Puck', 'Charon', 'Orus']];
    public const MAX_HABLADO = 700; // caracteres por respuesta hablada

    public function config(Business $b): array
    {
        $a = $b->ia_settings['audio'] ?? [];
        $sis = $this->claveSistema();
        return ['proveedor' => $a['proveedor'] ?? null, 'cargada' => ! empty($a['api_key']), 'mascara' => ! empty($a['api_key']) ? substr($a['api_key'], 0, 5) . '…' . substr($a['api_key'], -4) : null,
            'incluida' => (bool) $sis, 'proveedor_incluido' => $sis ? (self::PROVEEDORES[$sis['proveedor']] ?? $sis['proveedor']) : null]; // Fase 119.2
    }

    // Fase 119.2: la clave de voz de BigSys (Panel → Sistema → Voz de Bigcito) sirve a todas las empresas que no cargaron la suya.
    private function claveSistema(): ?array
    {
        try { $k = \App\Models\SistemaConfig::get('voz_api_key'); } catch (\Throwable $e) { return null; }
        return $k ? ['proveedor' => \App\Models\SistemaConfig::get('voz_proveedor') ?: 'openai', 'api_key' => $k, 'voz' => \App\Models\SistemaConfig::get('voz_nombre'), 'origen' => 'sistema'] : null;
    }

    // La clave con la que esta empresa escucha y habla: la suya, o la de BigSys.
    public function clave(?Business $b): ?array
    {
        $a = $b?->ia_settings['audio'] ?? [];
        if (! empty($a['api_key'])) return ['proveedor' => $a['proveedor'] ?? 'openai', 'api_key' => $a['api_key'], 'voz' => $a['voz'] ?? null, 'origen' => 'empresa'];
        return $this->claveSistema();
    }

    public function disponible(?Business $b): bool { return (bool) $this->clave($b); }

    // Fase 119.2: Bigcito habla con voz natural. Devuelve ['audio' => binario, 'mime' => …] o null si no hay con qué (el panel usa la voz del navegador).
    public function hablar(?Business $b, string $texto): ?array
    {
        $k = $this->clave($b); $texto = self::limpiarParaVoz($texto);
        if (! $k || $texto === '') return null;
        $voz = $k['voz'] && in_array($k['voz'], self::VOCES[$k['proveedor']] ?? [], true) ? $k['voz'] : (self::VOCES[$k['proveedor']][0] ?? 'coral');
        $cache = 'voz:' . md5($k['proveedor'] . '|' . $voz . '|' . $texto);
        if ($c = \Illuminate\Support\Facades\Cache::get($cache)) return ['audio' => base64_decode($c['a']), 'mime' => $c['m']];
        try {
            $r = $k['proveedor'] === 'gemini' ? $this->hablarGemini($k['api_key'], $texto, $voz) : $this->hablarOpenai($k['api_key'], $texto, $voz);
        } catch (\Throwable $e) { report($e); return null; }
        if ($r && strlen($r['audio']) < 400000) \Illuminate\Support\Facades\Cache::put($cache, ['a' => base64_encode($r['audio']), 'm' => $r['mime']], mb_strlen($texto) <= 60 ? 86400 * 7 : 3600);
        return $r;
    }

    // Lo que se dice en voz alta: sin enlaces, sin markdown, con los pesos dichos como se dicen.
    public static function limpiarParaVoz(string $t): string
    {
        $t = preg_replace('/https?:\/\/\S+/u', '', $t);
        $t = preg_replace('/\n?\s*SUGERENCIAS?:.*$/isu', '', $t);
        $t = preg_replace('/[*_#`|>]+/u', ' ', $t);
        $t = preg_replace_callback('/\$\s?([\d.]+)(?:,(\d{2}))?/u', fn($m) => str_replace('.', '', $m[1]) . ' pesos' . (isset($m[2]) && $m[2] !== '00' ? " con {$m[2]}" : ''), $t);
        return trim(mb_substr(trim(preg_replace('/\s+/u', ' ', $t)), 0, self::MAX_HABLADO));
    }

    private function hablarOpenai(string $key, string $texto, string $voz): ?array
    {
        $r = Http::withToken($key)->timeout(40)->post('https://api.openai.com/v1/audio/speech', ['model' => 'gpt-4o-mini-tts', 'voice' => $voz, 'input' => $texto, 'response_format' => 'mp3',
            'instructions' => 'Hablá en español rioplatense (Argentina), con voseo, tono cálido, claro y ágil, como una persona de confianza de la empresa. Los importes decilos naturales.']);
        if (! $r->successful() || strlen($r->body()) < 200) { \Illuminate\Support\Facades\Log::warning('Voz OpenAI: no habló', ['status' => $r->status(), 'error' => $r->json('error.message')]); return null; }
        return ['audio' => $r->body(), 'mime' => 'audio/mpeg'];
    }

    private function hablarGemini(string $key, string $texto, string $voz): ?array
    {
        $r = Http::timeout(40)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$key}", [
            'contents' => [['parts' => [['text' => 'Decí esto en español rioplatense, con tono cálido y ágil: ' . $texto]]]],
            'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voz]]]]]);
        $parte = collect((array) $r->json('candidates.0.content.parts', []))->first(fn($p) => isset($p['inlineData']['data']) || isset($p['inline_data']['data']));
        $d = $parte['inlineData'] ?? $parte['inline_data'] ?? null;
        if (! $r->successful() || ! $d) { \Illuminate\Support\Facades\Log::warning('Voz Gemini: no habló', ['status' => $r->status(), 'error' => $r->json('error.message')]); return null; }
        $pcm = base64_decode($d['data']); $mime = (string) ($d['mimeType'] ?? $d['mime_type'] ?? '');
        if (str_contains($mime, 'wav') || str_contains($mime, 'mpeg') || str_contains($mime, 'ogg')) return ['audio' => $pcm, 'mime' => explode(';', $mime)[0]];
        $rate = preg_match('/rate=(\d+)/', $mime, $m) ? (int) $m[1] : 24000;
        return ['audio' => self::wav($pcm, $rate), 'mime' => 'audio/wav'];
    }

    // PCM de 16 bits mono → WAV (los navegadores no reproducen PCM crudo).
    public static function wav(string $pcm, int $rate = 24000): string
    {
        $n = strlen($pcm);
        return 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', $n) . $pcm;
    }

    public function guardar(Business $b, string $proveedor, ?string $clave): void
    {
        abort_unless(isset(self::PROVEEDORES[$proveedor]), 422);
        $s = $b->ia_settings ?? []; $a = $s['audio'] ?? [];
        if ($clave) $a['api_key'] = trim($clave);
        if (empty($a['api_key'])) throw ValidationException::withMessages(['clave' => 'Pegá la clave de ' . self::PROVEEDORES[$proveedor] . '.']);
        $a['proveedor'] = $proveedor; $s['audio'] = $a;
        $b->forceFill(['ia_settings' => $s])->save();
    }

    public function transcribir(Business $b, string $path, string $mime = 'audio/webm'): string
    {
        $a = $this->clave($b); // Fase 119.2: la de la empresa o la de BigSys
        if (! $a) throw ValidationException::withMessages(['audio' => 'Para que Bigcito entienda audios hay que cargar una clave de OpenAI o de Gemini en Configuración → Conexiones IA (Claude no lee audio).']);
        $texto = match ($a['proveedor'] ?? 'openai') {
            'gemini' => $this->gemini($a['api_key'], $path, $mime),
            default => $this->openai($a['api_key'], $path, $mime),
        };
        if (trim($texto) === '') throw ValidationException::withMessages(['audio' => 'No se entendió el audio. Probá de nuevo, más cerca del micrófono.']);
        return trim($texto);
    }

    private function openai(string $key, string $path, string $mime): string
    {
        $ext = str_contains($mime, 'mp4') || str_contains($mime, 'm4a') ? 'm4a' : (str_contains($mime, 'ogg') ? 'ogg' : (str_contains($mime, 'mpeg') ? 'mp3' : 'webm'));
        $r = Http::withToken($key)->timeout(60)->attach('file', file_get_contents($path), "audio.{$ext}")->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'gpt-4o-mini-transcribe', 'language' => 'es']);
        if (! $r->successful()) throw ValidationException::withMessages(['audio' => 'OpenAI no pudo transcribir el audio (' . ($r->json('error.message') ?? $r->status()) . ').']);
        return (string) $r->json('text');
    }

    private function gemini(string $key, string $path, string $mime): string
    {
        $r = Http::timeout(60)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$key}", ['contents' => [['parts' => [['text' => 'Transcribí este audio en español tal cual lo dice, sin agregar nada.'], ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($path))]]]]]]);
        if (! $r->successful()) throw ValidationException::withMessages(['audio' => 'Gemini no pudo transcribir el audio (' . ($r->json('error.message') ?? $r->status()) . ').']);
        return (string) $r->json('candidates.0.content.parts.0.text');
    }

    // La foto como bloque para Claude (jpeg, png, webp o gif; hasta 5 MB).
    public static function bloqueImagen(string $dataUrl): ?array
    {
        if (! preg_match('/^data:(image\/(jpeg|png|webp|gif));base64,(.+)$/s', $dataUrl, $m)) return null;
        if (strlen($m[3]) > 7_000_000) throw ValidationException::withMessages(['imagen' => 'La foto es muy grande (máximo 5 MB).']);
        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[3]]];
    }
}
