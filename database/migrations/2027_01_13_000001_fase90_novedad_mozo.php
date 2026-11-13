<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 90: aviso en la campanita: el mozo de bolsillo y el mapa de calor.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Mozo de bolsillo y mapa de calor')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Mozo de bolsillo y mapa de calor', 'modulo' => 'gastronomia', 'url' => '/gastronomia/mozo',
            'texto' => 'Big Restó: en el celular, el mozo tiene su propia pantalla (Big Restó → Mozo de bolsillo): sus mesas con lo que falta enviar, lo que está en cocina y lo listo para llevar con un botón de Entregado, las mesas que lo llaman y las libres para abrir con un toque. Dentro de la comanda, en el celular, una barra abajo deja el total, Enviar y Cobrar siempre al pulgar. Y en el plano del salón hay una vista Calor: cada mesa pintada por lo que vendió en los últimos 30 días, con las que más rinden, las frías y la venta por franja horaria. A Bigcito: "¿qué mesas rinden más?".',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Mozo de bolsillo y mapa de calor')->delete(); }
};
