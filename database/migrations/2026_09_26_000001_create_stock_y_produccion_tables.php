<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 4: depósitos por sucursal, stock por depósito, rubros, inventarios, transferencias y producción con fórmulas.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rubros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('rubros')->nullOnDelete();
            $table->string('nombre', 80);
            $table->string('color', 10)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('depositos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->string('direccion')->nullable();
            $table->boolean('es_default')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_depositos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->cascadeOnDelete();
            $table->decimal('cantidad', 14, 3)->default(0);
            $table->decimal('stock_min', 14, 3)->nullable();   // mínimo propio del depósito (opcional)
            $table->string('ubicacion', 60)->nullable();       // estantería / pasillo
            $table->timestamps();
            $table->unique(['product_id', 'deposito_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('rubro_id')->nullable()->after('business_location_id')->constrained('rubros')->nullOnDelete();
            $table->string('tipo', 15)->default('producto')->after('sku');            // producto | insumo | elaborado | servicio
            $table->string('barcode', 40)->nullable()->after('tipo');
            $table->string('marca', 60)->nullable()->after('barcode');
            $table->foreignId('proveedor_id')->nullable()->after('marca')->constrained('contacts')->nullOnDelete();
            $table->boolean('controla_stock')->default(true)->after('active');
            $table->timestamp('precio_actualizado_en')->nullable()->after('controla_stock');
            $table->decimal('stock', 14, 3)->default(0)->change();
            $table->decimal('stock_min', 14, 3)->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('deposito_id')->nullable()->after('product_id')->constrained('depositos')->nullOnDelete();
            $table->decimal('costo_unit', 14, 2)->nullable()->after('stock_after');
            $table->decimal('quantity', 14, 3)->change();
            $table->decimal('stock_before', 14, 3)->change();
            $table->decimal('stock_after', 14, 3)->change();
            $table->unsignedBigInteger('movable_id')->nullable()->change();   // ajustes, inventarios y stock inicial no tienen comprobante
            $table->string('movable_type')->nullable()->change();
        });

        Schema::create('transferencias_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('numero');
            $table->date('fecha');
            $table->foreignId('origen_id')->constrained('depositos');
            $table->foreignId('destino_id')->constrained('depositos');
            $table->foreignId('user_id')->constrained('users');
            $table->string('estado', 15)->default('confirmada');   // confirmada | anulada
            $table->string('notas')->nullable();
            $table->timestamps();
        });

        Schema::create('transferencia_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transferencia_id')->constrained('transferencias_stock')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('cantidad', 14, 3);
        });

        Schema::create('inventarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedInteger('numero');
            $table->date('fecha');
            $table->string('estado', 15)->default('cerrado');
            $table->unsignedInteger('items_contados')->default(0);
            $table->unsignedInteger('items_con_diferencia')->default(0);
            $table->decimal('diferencia_valorizada', 14, 2)->default(0);
            $table->string('notas')->nullable();
            $table->timestamps();
        });

        Schema::create('inventario_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventario_id')->constrained('inventarios')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('sistema', 14, 3);
            $table->decimal('contado', 14, 3);
            $table->decimal('diferencia', 14, 3);
            $table->decimal('costo_unit', 14, 2)->default(0);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->decimal('costo_calculado', 14, 2)->default(0)->after('yield_unit');
            $table->unsignedSmallInteger('tiempo_minutos')->nullable()->after('costo_calculado');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('business_location_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            $table->foreignId('deposito_id')->nullable()->after('business_location_id')->constrained('depositos')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->after('recipe_id')->constrained('products')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('product_id')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('numero')->nullable()->after('user_id');
            $table->decimal('cantidad_producida', 12, 4)->nullable()->after('quantity');
            $table->foreignId('comprobante_id')->nullable()->after('notes')->constrained('comprobantes')->nullOnDelete();  // pedido de cliente que la originó
        });

        // Depósito principal para cada sucursal existente y stock inicial cargado en el depósito de la sucursal del artículo.
        foreach (DB::table('business_locations')->get() as $loc) {
            $depId = DB::table('depositos')->insertGetId(['business_id' => $loc->business_id, 'business_location_id' => $loc->id, 'nombre' => 'Depósito ' . $loc->name, 'es_default' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('products')->where('business_id', $loc->business_id)->where('business_location_id', $loc->id)->get() as $p) {
                DB::table('stock_depositos')->insert(['business_id' => $p->business_id, 'product_id' => $p->id, 'deposito_id' => $depId, 'cantidad' => $p->stock, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('production_orders', fn(Blueprint $t) => $t->dropColumn(['business_location_id', 'deposito_id', 'product_id', 'user_id', 'numero', 'cantidad_producida', 'comprobante_id']));
        Schema::table('recipes', fn(Blueprint $t) => $t->dropColumn(['costo_calculado', 'tiempo_minutos']));
        Schema::dropIfExists('inventario_items');
        Schema::dropIfExists('inventarios');
        Schema::dropIfExists('transferencia_stock_items');
        Schema::dropIfExists('transferencias_stock');
        Schema::table('stock_movements', fn(Blueprint $t) => $t->dropColumn(['deposito_id', 'costo_unit']));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['rubro_id', 'tipo', 'barcode', 'marca', 'proveedor_id', 'controla_stock', 'precio_actualizado_en']));
        Schema::dropIfExists('stock_depositos');
        Schema::dropIfExists('depositos');
        Schema::dropIfExists('rubros');
    }
};
