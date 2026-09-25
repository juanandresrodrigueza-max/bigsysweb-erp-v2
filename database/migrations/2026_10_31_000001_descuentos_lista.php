<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Descuentos especiales por lista de precios: para un artículo o un rubro, desde una cantidad mínima, con vigencia.
// Valen para todos los clientes de esa lista (el precio pactado de un cliente sigue mandando).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('descuentos_lista', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->unsignedTinyInteger('lista');
            $t->foreignId('product_id')->nullable()->index();
            $t->foreignId('rubro_id')->nullable();
            $t->decimal('cantidad_minima', 14, 3)->default(0);
            $t->decimal('precio', 14, 2)->nullable();   // precio neto unitario especial (solo por artículo)
            $t->decimal('descuento', 5, 2)->nullable(); // % de descuento (negativo = recargo)
            $t->date('vigente_desde')->nullable();
            $t->date('vigente_hasta')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'lista']);
        });
    }

    public function down(): void { Schema::dropIfExists('descuentos_lista'); }
};
