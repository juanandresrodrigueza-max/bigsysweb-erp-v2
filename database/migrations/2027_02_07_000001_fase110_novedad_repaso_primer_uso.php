<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 110: novedad para la campanita y la sección «Lo nuevo» de /conocer.
return new class extends Migration
{
    const TITULO = 'Repaso de todas las pantallas: más claro desde el primer día';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/ayuda/que-cambio',
            'texto' => 'Recorrimos las 142 pantallas en computadora y celular con una empresa recién creada. Un solo aviso arriba mientras el alta está pendiente, páginas de error con la marca que dicen qué pasó y qué permiso falta, el mostrador explica qué hacer cuando todavía no hay artículos, las alertas de stock bajo se cierran solas al reponer, ningún importe sale como «$ -0» y decimos ARCA en todos lados.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
