<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 120.6: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Encargos: dejale algo a alguien del equipo';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/encargos',
            'texto' => 'Decile a Bigcito «decile a Pedro que prepare el pedido 12 para las 17» o cargalo en la pantalla Encargos. A Pedro le llega por WhatsApp y por la campana, contesta HECHO y vos te enterás. Si se pasa la fecha, se lo recordamos solos. En el encabezado, el 📌 te muestra lo que tenés para hacer.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
