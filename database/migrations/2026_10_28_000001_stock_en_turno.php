<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cierre de turno con stock final: artículos que se cuentan al cerrar y la planilla que queda guardada en el turno.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->boolean('control_turno')->default(false));
        Schema::table('turnos_caja', function (Blueprint $t) {
            $t->json('stock')->nullable();
            $t->decimal('stock_importe', 14, 2)->nullable();   // lo que salió según el conteo, valorizado a precio de venta
            $t->decimal('stock_diferencia', 14, 2)->nullable(); // recaudación declarada − stock_importe
        });
    }

    public function down(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('control_turno'));
        Schema::table('turnos_caja', fn(Blueprint $t) => $t->dropColumn(['stock', 'stock_importe', 'stock_diferencia']));
    }
};
