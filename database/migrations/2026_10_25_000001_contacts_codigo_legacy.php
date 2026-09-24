<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.6: código del cliente o proveedor en el sistema anterior (codcta de BigSys). Con él se cruzan los saldos
// y se lo sigue encontrando por el número que la gente conoce.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('codigo', 20)->nullable();
            $t->index(['business_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) { $t->dropIndex(['business_id', 'codigo']); $t->dropColumn('codigo'); });
    }
};
