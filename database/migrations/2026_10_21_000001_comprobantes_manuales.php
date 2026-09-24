<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.2: comprobante manual de talonario. Se hizo en papel (talonario con CAI o factura manual de respaldo)
// y se carga con su número y fecha: no pasa por ARCA pero entra en IVA, cuenta corriente, stock y contabilidad.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->boolean('manual')->default(false);
            $t->string('cai', 20)->nullable();      // Código de Autorización de Impresión del talonario
            $t->date('cai_vto')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['manual', 'cai', 'cai_vto']));
    }
};
