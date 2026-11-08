<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 49: dividir la cuenta (lo pagado de cada ítem y los pagos de la comanda) y modificadores de cada plato.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comanda_items', function (Blueprint $t) { $t->decimal('pagado', 12, 3)->default(0); $t->json('modificadores')->nullable(); });
        Schema::table('comandas', fn(Blueprint $t) => $t->json('pagos')->nullable());
        Schema::table('products', fn(Blueprint $t) => $t->json('modificadores')->nullable());
    }
    public function down(): void
    {
        Schema::table('comanda_items', fn(Blueprint $t) => $t->dropColumn(['pagado', 'modificadores']));
        Schema::table('comandas', fn(Blueprint $t) => $t->dropColumn('pagos'));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('modificadores'));
    }
};
