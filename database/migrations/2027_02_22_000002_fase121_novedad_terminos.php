<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 121.1: aviso dentro del sistema de que cambiaron los términos y la privacidad (los términos piden avisar con 15 días).
return new class extends Migration
{
    const TITULO = 'Actualizamos los términos de uso y la política de privacidad';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'configuracion', 'url' => '/terminos#facturacion',
            'texto' => 'Sumamos a los términos cómo funciona la factura electrónica a través de BigSys: qué hace BigSys, qué sigue siendo responsabilidad de tu empresa y cómo se deja sin efecto. Sólo aplica si la activás, y para activarla se acepta expresamente. También explicamos cómo tratamos los datos de tu empresa por tu cuenta (Ley 25.326) y qué pasa al darte de baja: los datos quedan 90 días y después se eliminan, así que guardar tus comprobantes por el plazo legal es tuyo (bajá antes la copia completa). Los cambios rigen en 15 días.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
