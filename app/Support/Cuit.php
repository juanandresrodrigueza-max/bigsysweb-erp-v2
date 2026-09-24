<?php

namespace App\Support;

// CUIT/CUIL argentino: 11 dígitos con dígito verificador (módulo 11).
class Cuit
{
    public static function limpiar(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    public static function valido(?string $v): bool
    {
        $c = self::limpiar($v);
        if (strlen($c) !== 11 || ! in_array(substr($c, 0, 2), ['20', '23', '24', '25', '26', '27', '30', '33', '34'], true)) return false;
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) $suma += (int) $c[$i] * $pesos[$i];
        $dv = 11 - ($suma % 11);
        if ($dv === 11) $dv = 0; elseif ($dv === 10) $dv = 9;
        return $dv === (int) $c[10];
    }

    public static function formatear(?string $v): ?string
    {
        $c = self::limpiar($v);
        return strlen($c) === 11 ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10) : ($v ?: null);
    }
}
