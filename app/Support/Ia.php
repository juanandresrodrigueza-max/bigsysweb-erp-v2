<?php

namespace App\Support;

use App\Models\Business;

// La IA de cada empresa: su propia clave de Anthropic (cifrada en businesses.ia_settings) y el modelo que eligió.
// Toda la IA del sistema lee config('services.anthropic.*'); esto la apunta a la clave de la empresa (o a ninguna).
class Ia
{
    public const MODELOS = ['claude-haiku-5-5' => 'Haiku (rápido y económico)', 'claude-sonnet-5-5' => 'Sonnet (más inteligente)'];
    public const MODELO_DEFAULT = 'claude-haiku-5-5';

    public static function aplicar(?Business $b): void
    {
        $s = $b?->ia_settings ?? [];
        config(['services.anthropic.api_key' => $s['api_key'] ?? null, 'services.anthropic.model' => $s['modelo'] ?? self::MODELO_DEFAULT]);
    }

    public static function tiene(?Business $b): bool { return ! empty($b?->ia_settings['api_key']); }

    public static function mascara(?Business $b): ?string
    {
        $k = $b?->ia_settings['api_key'] ?? null;
        return $k ? substr($k, 0, 7) . '…' . substr($k, -4) : null;
    }

    // Fase 107: un texto corto redactado por la IA de la empresa, o null si no hay clave o falló (el que llama tiene su versión con reglas).
    public static function redactar(?Business $b, string $sistema, string $datos, int $maxTokens = 500, int $timeout = 25): ?string
    {
        if (! self::tiene($b)) return null;
        self::aplicar($b);
        try {
            $r = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01'])->timeout($timeout)
                ->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => $maxTokens, 'system' => $sistema, 'messages' => [['role' => 'user', 'content' => $datos]]]);
            $t = trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
            return $t !== '' ? $t : null;
        } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('IA redactar: ' . $e->getMessage()); return null; }
    }
}
