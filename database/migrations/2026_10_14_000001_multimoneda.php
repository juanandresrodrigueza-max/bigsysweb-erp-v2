<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Multimoneda completa: cotización del recibo/orden de pago y diferencia de cambio por imputación a facturas en dólares.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros', fn(Blueprint $t) => $t->decimal('cotizacion', 12, 4)->nullable());
        Schema::table('pagos', fn(Blueprint $t) => $t->decimal('cotizacion', 12, 4)->nullable());
        Schema::table('cobro_imputaciones', fn(Blueprint $t) => $t->decimal('dif_cambio', 14, 2)->default(0));
        Schema::table('pago_imputaciones', fn(Blueprint $t) => $t->decimal('dif_cambio', 14, 2)->default(0));
    }

    public function down(): void
    {
        Schema::table('cobros', fn(Blueprint $t) => $t->dropColumn('cotizacion'));
        Schema::table('pagos', fn(Blueprint $t) => $t->dropColumn('cotizacion'));
        Schema::table('cobro_imputaciones', fn(Blueprint $t) => $t->dropColumn('dif_cambio'));
        Schema::table('pago_imputaciones', fn(Blueprint $t) => $t->dropColumn('dif_cambio'));
    }
};
