<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 85: aviso en la campanita de los nombres nuevos de Plata y Stock y de la campana para todos.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Plata con nombres de todos los días')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Plata con nombres de todos los días', 'modulo' => 'fondos', 'url' => '/fondos/cierres',
            'texto' => 'En Plata, "Cierres" ahora es "Cerrar caja" (con el botón para cerrar la de hoy), "Cheques" y "Valores" son una sola sección "Cheques y valores" y "Previsiones" pasó a llamarse "Vencimientos previstos". En Stock, "Carnicería y pesables" es "Pesables y cortes" y "Consultor de precios" es "Simulador de precios"; las dos aparecen sólo si tu rubro las usa. La campana de alertas ahora es de todos los usuarios: ya no se asigna por rol.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Plata con nombres de todos los días')->delete(); }
};
