<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

// Política de contraseñas del sistema, en un solo lugar: mínimo 8, con letras y números.
class Clave
{
    public static function regla(): Password
    {
        return Password::min(8)->letters()->numbers();
    }

    public static function reglas(bool $confirmada = true): array
    {
        return array_filter(['required', 'string', self::regla(), $confirmada ? 'confirmed' : null]);
    }
}
