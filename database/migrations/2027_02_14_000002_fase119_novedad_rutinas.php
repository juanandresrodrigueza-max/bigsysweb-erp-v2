<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 119.4: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Rutinas: encargale algo a Bigcito y lo hace solo';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/rutinas',
            'texto' => 'Decile «todos los lunes a las 8 pasame cómo cerró la semana» o «avisame sólo si alguien pasa su límite de crédito» y Bigcito lo hace solo y te lo manda por la campana, WhatsApp o mail. Lo que sea una acción queda propuesto para que confirmes. Además ya le podés hablar desde cualquier celular, llamarlo con «Hola Bigcito» y pedirle varias cosas juntas.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
