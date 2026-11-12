<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 82: aviso en la campanita de los grupos de Configuración y Contable, y de Sueldos adentro de Contable.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Configuración y Contable, ordenados')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Configuración y Contable, ordenados', 'modulo' => 'dashboard', 'url' => '/configuracion',
            'texto' => 'Configuración quedó en cuatro grupos (Mi empresa · Ventas y fiscal · Integraciones · Datos y cuenta) con un buscador arriba: escribí "afip", "backup" o "2fa" y te lleva. Contable quedó en tres (Lo diario · Para el contador · Puesta a punto) y Sueldos ahora está adentro de Contable. Las direcciones no cambian: lo que tenías guardado sigue andando.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Configuración y Contable, ordenados')->delete(); }
};
