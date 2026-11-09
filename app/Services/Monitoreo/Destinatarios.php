<?php

namespace App\Services\Monitoreo;

use App\Models\SistemaConfig;

// Fase 76: a quién le llegan los avisos del sistema. Varios mails separados por coma (o punto y coma, o espacio).
// Lo cargado en Superadmin → Salud manda sobre MONITOREO_EMAIL del .env, y si no hay nada, el mail de soporte.
class Destinatarios
{
    public static function monitoreo(): array
    {
        $crudo = SistemaConfig::get('monitoreo_email') ?: config('services.monitoreo.email') ?: SistemaConfig::get('soporte_email');
        return self::lista((string) $crudo);
    }

    public static function lista(string $texto): array
    {
        return array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim($e)), preg_split('/[,;\s]+/', $texto) ?: []), fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
}
