<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 111: novedad para la campanita y la sección «Lo nuevo» de /conocer.
return new class extends Migration
{
    const TITULO = 'Mandale un audio a Bigcito por WhatsApp y lo hace';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/ayuda/alertas-y-asistente',
            'texto' => 'Cargá tu celular en Configuración → Usuarios y escribile o mandale un audio al WhatsApp de la empresa: «cobrale 5000 a López», «facturale a Pérez dos bolsas de cemento», «cuánto vendí hoy». Bigcito te contesta con la propuesta, espera tu SÍ y lo hace con tus permisos. En el panel, el botón 🎙️ de manos libres vuelve a escucharte después de contestarte, y Alt + B lo abre desde cualquier pantalla.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
