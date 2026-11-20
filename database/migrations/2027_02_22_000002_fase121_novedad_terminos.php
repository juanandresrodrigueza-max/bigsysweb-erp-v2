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
            'texto' => 'Sumamos a los términos cómo funciona la factura electrónica a través de BigSys: qué hace BigSys, qué sigue siendo responsabilidad de tu empresa y cómo se deja sin efecto. Sólo aplica si la activás, y para activarla se acepta expresamente. En la política de privacidad aclaramos que los datos de tus clientes son de tu empresa y que BigSys los trata sólo para prestarte el servicio. Los cambios rigen en 15 días.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
