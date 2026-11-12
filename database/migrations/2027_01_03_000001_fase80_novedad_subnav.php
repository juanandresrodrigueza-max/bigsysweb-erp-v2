<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 80: aviso en la campanita de la sub-navegación por módulo y la cabecera con una acción principal y "Más".
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Las secciones, a la izquierda')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Las secciones, a la izquierda', 'modulo' => 'dashboard', 'url' => '/ayuda/primeros-pasos',
            'texto' => 'Adentro de Vender, Stock, Plata, Contable, Configuración y los demás módulos, las secciones ahora van en una columna a la izquierda (en el celular, chips arriba) en vez de una fila de botones. Cada pantalla quedó con una acción principal y un botón "Más" con el resto. Nada cambió de lugar: la sub-navegación es la misma en todos lados.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Las secciones, a la izquierda')->delete(); }
};
