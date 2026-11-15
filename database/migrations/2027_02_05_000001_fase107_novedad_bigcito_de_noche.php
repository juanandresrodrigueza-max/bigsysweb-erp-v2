<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 107: aviso en la campanita (y en la página de ventas): Bigcito trabaja de noche.
return new class extends Migration
{
    private const TITULO = 'Bigcito trabaja de noche: cobrador, reposición, precios, cierre del mes y control interno';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/ayuda/alertas-y-asistente',
            'texto' => 'Cada madrugada cinco agentes revisan tu empresa y te dejan propuestas en Inicio: a quién cobrarle con el mensaje ya escrito, la orden de compra por proveedor, el precio nuevo cuando sube el costo, los ajustes del cierre del mes y quién del equipo se sale de lo normal. Nada se hace solo: aprobás de a un toque. Y el parte de la mañana ahora se escucha: «Resumen hablado». A Bigcito: «¿qué me dejaste para aprobar?», «aprobá la propuesta 12».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
