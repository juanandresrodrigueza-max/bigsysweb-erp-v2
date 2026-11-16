<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 114: novedad para la campanita y la sección «Lo nuevo» de /conocer.
return new class extends Migration
{
    const TITULO = 'Big Restó: la historia de cada plato, mes a mes';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'gastronomia', 'url' => '/ayuda/gastronomia',
            'texto' => 'En la Carta, cada plato tiene «Historia»: cómo fue cambiando el costo de la receta, el precio y el margen en los últimos doce meses, y qué insumo explicó cada salto («Pavo $ 8.000 → $ 9.200, +$ 240 por porción»). No hay que cargar nada: sale de la receta y de cada compra. Preguntale a Bigcito «¿cómo vino el costo de la milanesa?».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
