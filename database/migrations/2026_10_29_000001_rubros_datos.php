<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Datos por rubro que heredan sus subrubros y artículos: IVA, tipo, marcas de stock, cuenta de ventas,
// percepciones especiales y foto para la tienda. Nulo = hereda del rubro padre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubros', function (Blueprint $t) {
            $t->decimal('iva', 5, 2)->nullable();
            $t->string('tipo', 20)->nullable();
            foreach (['perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda'] as $c) $t->boolean($c)->nullable();
            $t->foreignId('cuenta_ventas_id')->nullable();
            $t->decimal('perc_iva', 5, 2)->nullable();
            $t->decimal('perc_iibb', 5, 2)->nullable();
            $t->string('imagen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rubros', fn(Blueprint $t) => $t->dropColumn(['iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda', 'cuenta_ventas_id', 'perc_iva', 'perc_iibb', 'imagen']));
    }
};
