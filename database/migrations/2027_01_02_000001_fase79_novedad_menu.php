<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 79: aviso en la campanita de que el menú cambió de orden y de nombres.
return new class extends Migration
{
    public function up(): void
    {
        // Es un dato de producción, no un fixture: en las pruebas no se siembra para no alterar los conteos de novedades.
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Reordenamos el menú')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Reordenamos el menú', 'modulo' => 'dashboard', 'url' => '/ayuda/que-cambio',
            'texto' => 'Ahora va en el orden del día del negocio: Vender (antes Comprobantes), Cobrar, Entregar, Clientes, Comprar (antes Proveedores), Stock, Plata (antes Fondos), Contable, Estadísticas, lo de tu rubro y Configuración. Nada se movió de lugar adentro: sólo cambian los nombres y el orden del menú. Las alertas quedan en la campanita.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Reordenamos el menú')->delete(); }
};
