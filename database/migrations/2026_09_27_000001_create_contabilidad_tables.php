<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 5: plan de cuentas, asientos (automáticos y manuales) y conciliación bancaria.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cuentas_contables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cuentas_contables')->nullOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre', 120);
            $table->string('tipo', 12);                       // activo | pasivo | patrimonio | ingreso | egreso
            $table->string('clave', 40)->nullable();           // rol que usa el sistema para los asientos automáticos (caja, ventas, iva_df…)
            $table->boolean('imputable')->default(true);       // false = título / agrupadora
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'codigo']);
            $table->index(['business_id', 'clave']);
        });

        Schema::create('asientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('numero');
            $table->date('fecha');
            $table->string('concepto');
            $table->string('origen', 20)->default('manual');   // venta | compra | cobro | pago | fondos | manual
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->string('estado', 12)->default('confirmado'); // confirmado | anulado
            $table->timestamps();
            $table->index(['business_id', 'fecha']);
            $table->index(['business_id', 'origen', 'origen_id']);
        });

        Schema::create('asiento_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asiento_id')->constrained('asientos')->cascadeOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas_contables');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->decimal('debe', 14, 2)->default(0);
            $table->decimal('haber', 14, 2)->default(0);
            $table->string('detalle')->nullable();
            $table->index(['cuenta_id']);
        });

        Schema::create('extractos_bancarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuenta_fondos_id')->constrained('cuentas_fondos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('archivo')->nullable();
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->unsignedInteger('items')->default(0);
            $table->decimal('saldo_final', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('extracto_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extracto_id')->constrained('extractos_bancarios')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuenta_fondos_id')->constrained('cuentas_fondos')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('descripcion');
            $table->string('referencia', 80)->nullable();
            $table->decimal('monto', 14, 2);                   // positivo acredita, negativo debita
            $table->decimal('saldo', 14, 2)->nullable();
            $table->string('estado', 12)->default('pendiente'); // pendiente | conciliado | ignorado
            $table->string('match', 10)->nullable();           // auto | manual
            $table->foreignId('movimiento_fondos_id')->nullable()->constrained('movimientos_fondos')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'cuenta_fondos_id', 'estado']);
        });

        Schema::table('movimientos_fondos', function (Blueprint $table) {
            $table->timestamp('conciliado_en')->nullable()->after('conciliado');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_fondos', fn(Blueprint $t) => $t->dropColumn('conciliado_en'));
        Schema::dropIfExists('extracto_items');
        Schema::dropIfExists('extractos_bancarios');
        Schema::dropIfExists('asiento_lineas');
        Schema::dropIfExists('asientos');
        Schema::dropIfExists('cuentas_contables');
    }
};
