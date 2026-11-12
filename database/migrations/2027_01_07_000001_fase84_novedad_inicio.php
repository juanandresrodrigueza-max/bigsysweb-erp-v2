<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 84: aviso en la campanita de los accesos del día en Inicio y los retoques de Cobrar, Comprar y la ficha del cliente.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Inicio con los accesos del día')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Inicio con los accesos del día', 'modulo' => 'dashboard', 'url' => '/ayuda/que-cambio',
            'texto' => 'Inicio arranca con los accesos del día: Vender, Cobrar, Entregar y Cerrar caja, arriba de los números. Cobrar tiene "Registrar cobro" como botón principal, Comprar tiene "Nueva orden", y la ficha del cliente abre por la solapa Ficha (Acopios y Precios pactados aparecen sólo si los usás). Con una sola sucursal, el encabezado ya no ofrece cambiarla; el dueño tiene "Mi negocio" también en la compu.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Inicio con los accesos del día')->delete(); }
};
