<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 33.3: márgenes a vigilar (mínimo por artículo, por rubro o general) y el historial de precio y costo de cada artículo.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'margen_minimo')) Schema::table('products', fn(Blueprint $t) => $t->decimal('margen_minimo', 6, 2)->nullable());
        if (! Schema::hasColumn('rubros', 'margen_minimo')) Schema::table('rubros', fn(Blueprint $t) => $t->decimal('margen_minimo', 6, 2)->nullable());
        if (! Schema::hasTable('precio_historial')) {
            Schema::create('precio_historial', function (Blueprint $t) {
                $t->id();
                $t->foreignId('business_id')->constrained()->cascadeOnDelete();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->nullable();
                $t->date('fecha');
                $t->decimal('precio', 14, 2)->default(0);
                $t->decimal('costo', 14, 2)->default(0);
                $t->decimal('precio_antes', 14, 2)->nullable();
                $t->decimal('costo_antes', 14, 2)->nullable();
                $t->timestamps();
                $t->index(['product_id', 'fecha']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('precio_historial');
        Schema::table('rubros', fn(Blueprint $t) => $t->dropColumn('margen_minimo'));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('margen_minimo'));
    }
};
