<?php

namespace App\Support;

// Fase 110: números como los lee una persona. Cantidades sin ceros de más («12», «2,5», «0,125»), pesos sin centavos salvo que haga falta.
class Formato
{
    public static function cantidad($n, int $max = 3): string
    {
        $n = (float) $n; $s = number_format($n, $max, ',', '.');
        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    public static function pesos($n, bool $centavos = false): string
    {
        return '$ ' . number_format((float) $n, $centavos ? 2 : 0, ',', '.');
    }
}
