<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 83: aviso en la campanita de los canales en Vender, Qué reponer y Pagos en Comprar y Mi rubro como una sola entrada.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Canales en Vender, reposición y pagos en Comprar')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Canales en Vender, reposición y pagos en Comprar', 'modulo' => 'dashboard', 'url' => '/ayuda/que-cambio',
            'texto' => 'Vender arranca por el Mostrador y tiene un grupo Canales con Pedidos web, Tienda online y la configuración de canales. Comprar arranca por "Qué reponer" (lo que está en el mínimo, por proveedor, con el botón para armar la orden) y termina en "Pagos" (todas las órdenes de pago a proveedores). Y lo de tu rubro quedó en una sola entrada del menú, con sus pantallas adentro. Las direcciones no cambian.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Canales en Vender, reposición y pagos en Comprar')->delete(); }
};
