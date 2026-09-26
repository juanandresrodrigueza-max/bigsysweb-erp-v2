<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 27.3: productos pesables (código PLU de balanza) y despiece de carnicería (media res → cortes) con rinde y costo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('pesable')->default(false);
            $t->string('plu', 6)->nullable();
            $t->unsignedSmallInteger('dias_vencimiento')->nullable(); // para la etiqueta de la balanza
        });
        Schema::create('despieces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->string('nombre', 100);
            $t->foreignId('product_id');         // materia prima: media res, cuarto, cerdo, pollo…
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::create('despiece_cortes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('despiece_id')->index();
            $t->foreignId('product_id');
            $t->decimal('rinde', 6, 3)->default(0); // % esperado sobre el peso de entrada
            $t->unsignedSmallInteger('orden')->default(0);
        });
        Schema::create('despiece_operaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('despiece_id');
            $t->foreignId('deposito_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->date('fecha');
            $t->decimal('kg_entrada', 12, 3);
            $t->decimal('costo_total', 14, 2);
            $t->decimal('kg_salida', 12, 3);
            $t->decimal('merma_kg', 12, 3);
            $t->string('notas', 300)->nullable();
            $t->timestamps();
        });
        Schema::create('despiece_operacion_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('operacion_id')->index();
            $t->foreignId('product_id');
            $t->decimal('kg', 12, 3);
            $t->decimal('rinde_real', 6, 3);
            $t->decimal('rinde_esperado', 6, 3)->nullable();
            $t->decimal('precio_venta', 14, 2);
            $t->decimal('costo_kg', 14, 4);
        });
    }

    public function down(): void
    {
        foreach (['despiece_operacion_items', 'despiece_operaciones', 'despiece_cortes', 'despieces'] as $t) Schema::dropIfExists($t);
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['pesable', 'plu', 'dias_vencimiento']));
    }
};
