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

    public function config(Business $b): array
    {
        $a = $b->ia_settings['audio'] ?? [];
        return ['proveedor' => $a['proveedor'] ?? null, 'cargada' => ! empty($a['api_key']), 'mascara' => ! empty($a['api_key']) ? substr($a['api_key'], 0, 5) . '…' . substr($a['api_key'], -4) : null];
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
        $a = $b->ia_settings['audio'] ?? [];
        if (empty($a['api_key'])) throw ValidationException::withMessages(['audio' => 'Para que Bigcito entienda audios hay que cargar una clave de OpenAI o de Gemini en Configuración → Conexiones IA (Claude no lee audio).']);
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
