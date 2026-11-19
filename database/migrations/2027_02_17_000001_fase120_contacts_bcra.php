<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 120.2: la última consulta a la Central de Deudores del BCRA queda guardada en el cliente o proveedor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            if (! Schema::hasColumn('contacts', 'bcra')) $t->json('bcra')->nullable();
            if (! Schema::hasColumn('contacts', 'bcra_en')) $t->timestamp('bcra_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) { foreach (['bcra', 'bcra_en'] as $c) if (Schema::hasColumn('contacts', $c)) $t->dropColumn($c); });
    }
};
