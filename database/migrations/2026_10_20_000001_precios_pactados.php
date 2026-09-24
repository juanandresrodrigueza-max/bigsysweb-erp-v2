<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.1: condiciones comerciales por cliente. Precio fijo o descuento por artículo, descuento por rubro
// (alcanza a los subrubros) y, si el cliente lo tiene activo, el último precio facturado queda como pactado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_pactados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('rubro_id')->nullable()->constrained('rubros')->cascadeOnDelete();
            $t->decimal('precio', 14, 2)->nullable();      // precio neto unitario fijo (solo por artículo)
            $t->decimal('descuento', 5, 2)->nullable();    // % de descuento
            $t->string('origen', 10)->default('manual');   // manual | ultimo (último precio facturado)
            $t->date('vigente_hasta')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['contact_id', 'product_id', 'rubro_id']);
            $t->index(['business_id', 'contact_id']);
        });
        Schema::table('contacts', function (Blueprint $t) {
            $t->boolean('recordar_precio')->default(false); // el último precio facturado de cada artículo queda como pactado
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precios_pactados');
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn('recordar_precio'));
    }
};
