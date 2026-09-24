<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// Expresiones SQL que cambian según el motor (SQLite en desarrollo, PostgreSQL o MySQL en producción).
class Sql
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    // 'YYYY-MM' de una columna fecha.
    public static function mes(string $col): string
    {
        return match (self::driver()) {
            'pgsql' => "to_char({$col}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%Y-%m')",
            default => "strftime('%Y-%m', {$col})",
        };
    }

    // Hora (0-23) de una columna datetime, como entero.
    public static function hora(string $col): string
    {
        return match (self::driver()) {
            'pgsql' => "CAST(EXTRACT(HOUR FROM {$col}) AS INTEGER)",
            'mysql', 'mariadb' => "HOUR({$col})",
            default => "CAST(strftime('%H', {$col}) AS INTEGER)",
        };
    }

    // Fecha (sin hora) de una columna datetime.
    public static function fecha(string $col): string
    {
        return match (self::driver()) {
            'pgsql' => "CAST({$col} AS DATE)",
            'mysql', 'mariadb' => "DATE({$col})",
            default => "date({$col})",
        };
    }

    // Días enteros desde una columna fecha hasta una fecha dada ('YYYY-MM-DD'), como expresión SQL.
    public static function diasHasta(string $col, string $fecha): string
    {
        $f = "'" . preg_replace('/[^0-9-]/', '', $fecha) . "'";
        return match (self::driver()) {
            'pgsql' => "(DATE {$f} - {$col})",
            'mysql', 'mariadb' => "DATEDIFF({$f}, {$col})",
            default => "CAST(julianday({$f}) - julianday({$col}) AS INTEGER)",
        };
    }

    // Comparación de texto sin distinguir mayúsculas (ILIKE en Postgres, LIKE en el resto).
    public static function like(): string
    {
        return self::driver() === 'pgsql' ? 'ilike' : 'like';
    }
}
