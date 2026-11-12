<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 81: aviso en la campanita de los estados vacíos y los consejos de la primera semana.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Los listados vacíos te dicen qué hacer')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Los listados vacíos te dicen qué hacer', 'modulo' => 'dashboard', 'url' => '/ayuda/que-cambio',
            'texto' => 'Cuando una lista está vacía ahora dice si es porque todavía no hay nada (y qué hacer para empezar) o porque no hay nada con los filtros puestos (con un botón para limpiarlos). Y a cada usuario nuevo le mostramos un consejo por día durante la primera semana: Ctrl K, el ?, el asistente, los atajos.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Los listados vacíos te dicen qué hacer')->delete(); }
};
