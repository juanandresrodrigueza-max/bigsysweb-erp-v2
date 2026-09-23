<?php

namespace App\Models\Concerns;

// Los campos con cast "date" se guardan como YYYY-MM-DD (sin hora). Sin esto, Laravel escribe "YYYY-MM-DD 00:00:00" y en SQLite
// las comparaciones del mismo día (where fecha = hoy, between hoy y hoy) no encuentran nada. En PostgreSQL/MySQL la columna date lo corrige sola.
trait FechaSoloDia
{
    public static function bootFechaSoloDia(): void
    {
        static::saving(function ($m) {
            foreach ($m->getCasts() as $campo => $cast) {
                if (($cast === 'date' || str_starts_with((string) $cast, 'date:')) && isset($m->attributes[$campo]) && is_string($m->attributes[$campo]) && strlen($m->attributes[$campo]) > 10) {
                    $m->attributes[$campo] = substr($m->attributes[$campo], 0, 10);
                }
            }
        });
    }
}
