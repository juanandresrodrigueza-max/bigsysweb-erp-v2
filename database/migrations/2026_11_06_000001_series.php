<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 27.2: números de serie. Garantía del artículo, y en cada serie a quién se vendió, cuándo y hasta cuándo tiene garantía.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->unsignedSmallInteger('garantia_meses')->nullable());
        Schema::table('lotes', function (Blueprint $t) {
            $t->foreignId('cliente_id')->nullable();
            $t->foreignId('comprobante_venta_id')->nullable();
            $t->date('vendido_en')->nullable();
            $t->date('garantia_hasta')->nullable();
            $t->index(['business_id', 'serie']);
        });
    }

    public function down(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('garantia_meses'));
        Schema::table('lotes', function (Blueprint $t) { $t->dropIndex(['business_id', 'serie']); $t->dropColumn(['cliente_id', 'comprobante_venta_id', 'vendido_en', 'garantia_hasta']); });
    }
};
