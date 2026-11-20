<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 121: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Factura electrónica sin certificado';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'configuracion', 'url' => '/configuracion/puntos-venta',
            'texto' => 'Ya no hace falta generar ni subir un certificado de ARCA: BigSys factura por tu empresa. Autorizás a BigSys una sola vez en ARCA (Administrador de Relaciones de Clave Fiscal) y lo activás en Configuración → Puntos de venta y ARCA, que te guía paso a paso, verifica los trámites con ARCA y trae tus puntos de venta.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
