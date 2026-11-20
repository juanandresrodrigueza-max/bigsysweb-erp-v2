<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 122: Primeros pasos por etapas, con «Siguiente paso» y el camino desde BigSys de escritorio.
return new class extends Migration
{
    const TITULO = 'Primeros pasos más claros: siempre sabés qué sigue';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/primeros-pasos',
            'texto' => 'Primeros pasos ahora va en etapas (tu empresa, tus datos, la plata y el equipo, a vender) y arriba te muestra el siguiente paso: qué es, para qué sirve y cuánto lleva. La barra de arriba de cada pantalla también dice qué sigue. Si venís de BigSys de escritorio, hay un paso para traer todos tus datos con BIG Connect, y otro para conectar el WhatsApp de la empresa con un QR. La guía de implementación se reescribió con el mismo orden.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
