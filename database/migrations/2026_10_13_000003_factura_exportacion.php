<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Factura de exportación (WSFEX): país y CUIT país del cliente del exterior, y datos de la operación en el comprobante.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('pais_codigo', 4)->nullable();
            $t->string('cuit_pais', 15)->nullable();
            $t->string('id_impositivo', 40)->nullable();
        });
        Schema::table('comprobantes', fn(Blueprint $t) => $t->json('exportacion')->nullable());
    }

    public function down(): void
    {
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn(['pais_codigo', 'cuit_pais', 'id_impositivo']));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn('exportacion'));
    }
};
