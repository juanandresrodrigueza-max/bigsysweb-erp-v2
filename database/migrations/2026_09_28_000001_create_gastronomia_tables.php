<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 6: verticales. Gastronomía (mesas, comandas, cocina); el punto de venta usa los comprobantes existentes.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 30);
            $table->string('sector', 40)->nullable();          // Salón, Terraza, Barra…
            $table->unsignedSmallInteger('capacidad')->default(4);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('comandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mesa_id')->nullable()->constrained('mesas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // mozo
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->unsignedInteger('numero');
            $table->string('tipo', 12)->default('mesa');        // mesa | mostrador | delivery
            $table->string('estado', 12)->default('abierta');   // abierta | cuenta | cerrada | anulada
            $table->unsignedSmallInteger('cubiertos')->default(0);
            $table->string('cliente', 120)->nullable();         // nombre para delivery / mostrador
            $table->string('direccion', 200)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('propina', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamp('abierta_en')->nullable();
            $table->timestamp('cuenta_en')->nullable();
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'estado']);
        });

        Schema::create('comanda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comanda_id')->constrained('comandas')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('descripcion', 150);
            $table->decimal('cantidad', 10, 3)->default(1);
            $table->decimal('precio_unit', 14, 2)->default(0);
            $table->decimal('alicuota_iva', 5, 2)->default(21);
            $table->string('notas', 150)->nullable();           // "sin cebolla", "bien cocido"
            $table->string('estado', 12)->default('pedido');    // pedido | cocina | listo | entregado | anulado
            $table->boolean('va_cocina')->default(true);        // bebidas no pasan por cocina
            $table->unsignedSmallInteger('ronda')->default(1);  // 1er pedido, 2do pedido…
            $table->timestamp('enviado_en')->nullable();
            $table->timestamp('listo_en')->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('va_cocina')->default(true)->after('controla_stock');   // gastronomía: se prepara en cocina o sale directo (bebidas)
            $table->boolean('favorito_pos')->default(false)->after('va_cocina');    // acceso rápido en el punto de venta
        });
    }

    public function down(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['va_cocina', 'favorito_pos']));
        Schema::dropIfExists('comanda_items');
        Schema::dropIfExists('comandas');
        Schema::dropIfExists('mesas');
    }
};
