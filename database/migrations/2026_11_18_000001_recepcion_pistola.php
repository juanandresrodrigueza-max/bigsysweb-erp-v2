<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 31.5: controlar lo que baja del camión con la pistola, contra la factura. Códigos internos que se aprenden,
// lote y vencimiento del GS1-128, diferencias renglón por renglón y reclamos al proveedor que se cierran con la nota de crédito.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable();
            $t->string('estado', 12)->default('abierta');   // abierta | cerrada
            $t->unsignedInteger('lecturas')->default(0);
            $t->json('ultimas')->nullable();                 // últimas lecturas (para retomar y ver qué pasó)
            $t->timestamp('cerrada_en')->nullable();
            $t->timestamps();
        });
        Schema::create('recepcion_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recepcion_id')->constrained('recepciones')->cascadeOnDelete();
            $t->foreignId('comprobante_item_id')->nullable();  // null = llegó algo que no está en la factura
            $t->foreignId('product_id')->nullable();
            $t->string('descripcion');
            $t->decimal('esperado', 12, 3)->default(0);
            $t->decimal('contado', 12, 3)->default(0);
            $t->string('resolucion', 12)->nullable();        // contado | facturado | pendiente
            $t->string('lote', 60)->nullable();
            $t->date('vencimiento')->nullable();
            $t->timestamps();
        });
        // Un código que la pistola lee y no es el de la ficha: queda aprendido para ese artículo en este negocio.
        Schema::create('codigos_pistola', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('codigo', 80);
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'codigo']);
        });
        Schema::create('reclamos_compra', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('comprobante_id')->nullable();
            $t->foreignId('recepcion_id')->nullable();
            $t->string('estado', 12)->default('abierto');   // abierto | resuelto
            $t->json('detalle');                             // renglones: descripcion, product_id, facturado, llego, diferencia, tipo (falto|sobro|pendiente)
            $t->text('texto');
            $t->string('resolucion')->nullable();
            $t->foreignId('nota_credito_id')->nullable();
            $t->foreignId('resuelto_por')->nullable();
            $t->timestamp('resuelto_en')->nullable();
            $t->timestamps();
        });
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->decimal('cantidad_recibida', 12, 3)->nullable()->after('cantidad'));
        Schema::table('businesses', fn(Blueprint $t) => $t->json('compras_config')->nullable());
    }

    public function down(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('compras_config'));
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->dropColumn('cantidad_recibida'));
        foreach (['reclamos_compra', 'codigos_pistola', 'recepcion_items', 'recepciones'] as $x) Schema::dropIfExists($x);
    }
};
