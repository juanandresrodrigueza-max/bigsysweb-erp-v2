<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// En SQLite las columnas date quedaron guardadas con hora ("2026-09-23 00:00:00"); se normalizan a YYYY-MM-DD para que las comparaciones por día funcionen.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') return;
        foreach (Schema::getTableListing() as $tabla) {
            foreach (Schema::getColumns($tabla) as $col) {
                if (strtolower($col['type_name'] ?? $col['type'] ?? '') !== 'date') continue;
                DB::statement("UPDATE \"{$tabla}\" SET \"{$col['name']}\" = substr(\"{$col['name']}\", 1, 10) WHERE \"{$col['name']}\" IS NOT NULL AND length(\"{$col['name']}\") > 10");
            }
        }
    }

    public function down(): void {}
};
