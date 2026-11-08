<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 53: llamador de pedidos (cuándo se avisó al cliente) y margen objetivo por plato.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comandas', fn(Blueprint $t) => $t->timestamp('aviso_listo_en')->nullable());
        Schema::table('products', fn(Blueprint $t) => $t->decimal('margen_objetivo', 6, 2)->nullable());
    }
    public function down(): void
    {
        Schema::table('comandas', fn(Blueprint $t) => $t->dropColumn('aviso_listo_en'));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('margen_objetivo'));
    }
};
