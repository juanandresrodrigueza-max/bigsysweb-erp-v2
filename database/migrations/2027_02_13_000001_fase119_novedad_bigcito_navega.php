<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 119.1: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Bigcito te lleva por el sistema: «abrime la ficha de López»';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/ayuda/alertas-y-asistente',
            'texto' => 'Hablale (Alt+B) o escribile: «llevame a cerrar caja», «abrime la ficha de López», «nueva factura para García», «imprimí la factura 123». Además sabe qué estás mirando: con un cliente abierto alcanza con «¿cuánto me debe?» o «cobrale 50.000». La charla sigue aunque cambie de pantalla.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
