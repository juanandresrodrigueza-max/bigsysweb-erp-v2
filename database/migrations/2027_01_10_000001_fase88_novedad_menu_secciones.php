<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 88: aviso en la campanita: las secciones cuelgan del menú y cada rubro es un módulo.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Las secciones, en el menú')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Las secciones, en el menú', 'modulo' => 'dashboard', 'url' => '/ayuda/que-cambio',
            'texto' => 'Las secciones de cada módulo ahora cuelgan del menú de la izquierda: tocás Stock y debajo aparecen Artículos, Precios, Inventario…; la flecha las pliega. La página queda a todo el ancho. Y los rubros que tengas prendidos (Big Restó, Servicio técnico, Hotelería…) son módulos como los demás, con sus pantallas debajo: Mesas, Cocina, Carta, Reservas.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Las secciones, en el menú')->delete(); }
};
