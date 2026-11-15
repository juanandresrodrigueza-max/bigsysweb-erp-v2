<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 101: ventas por artículo y día traídas del sistema anterior (BigSys por BIG Connect), para que «Qué reponer», la ficha del
// artículo y Bigcito tengan historia desde el primer día.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas_historicas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->date('fecha');
            $t->decimal('cantidad', 14, 3)->default(0);
            $t->decimal('importe', 14, 2)->default(0);
            $t->decimal('costo', 14, 2)->default(0);
            $t->string('origen', 20)->default('bigsys');
            $t->timestamps();
            $t->unique(['business_id', 'product_id', 'fecha', 'origen']);
            $t->index(['business_id', 'fecha']);
        });
    }

    public function down(): void { Schema::dropIfExists('ventas_historicas'); }
};
