<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 117: novedad para la campanita y la sección «Lo nuevo» de /conocer.
return new class extends Migration
{
    const TITULO = 'Tu plan se cobra solo: débito automático con Mercado Pago';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'configuracion', 'url' => '/suscripcion',
            'texto' => 'En Configuración → Suscripción elegí «Débito automático con Mercado Pago»: dejás la tarjeta una sola vez y el plan se renueva solo cada mes o cada año, sin vencimientos ni cortes. Lo cancelás cuando quieras desde la misma pantalla. El pago único con link y la transferencia siguen estando.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
