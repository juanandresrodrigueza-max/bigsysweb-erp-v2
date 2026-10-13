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
}
