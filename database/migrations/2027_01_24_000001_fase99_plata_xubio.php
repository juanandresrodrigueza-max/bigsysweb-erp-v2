<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 99 · Lo de Xubio en Plata: reglas de conciliación que actúan solas, movimientos de Mercado Pago por API y la posición mensual de IVA con arrastre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conciliacion_reglas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('cuenta_fondos_id')->nullable()->constrained('cuentas_fondos')->nullOnDelete(); // null: para todas las cuentas
            $t->string('patron', 160);              // lo que tiene que decir el renglón del extracto (normalizado, "contiene")
            $t->string('sentido', 10)->default('ambos'); // ingreso | egreso | ambos
            $t->string('accion', 20);               // gasto | ignorar | recibo
            $t->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $t->string('concepto', 150)->nullable();
            $t->unsignedInteger('veces')->default(0);
            $t->timestamp('ultima_vez')->nullable();
            $t->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['business_id', 'cuenta_fondos_id']);
        });
        Schema::create('posiciones_iva', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->char('mes', 7);
            $t->decimal('debito', 14, 2)->default(0);
            $t->decimal('credito', 14, 2)->default(0);
            $t->decimal('percepciones', 14, 2)->default(0);   // percepciones de IVA sufridas en compras
            $t->decimal('retenciones', 14, 2)->default(0);    // retenciones de IVA sufridas en cobros
            $t->decimal('saldo_anterior_tecnico', 14, 2)->default(0);
            $t->decimal('saldo_anterior_libre', 14, 2)->default(0);
            $t->decimal('ajuste', 14, 2)->default(0);         // + suma al débito, − suma al crédito (crédito no computable, ajustes del contador)
            $t->string('ajuste_nota', 200)->nullable();
            $t->decimal('a_pagar', 14, 2)->default(0);
            $t->decimal('saldo_tecnico', 14, 2)->default(0);  // a favor, sólo contra IVA futuro
            $t->decimal('saldo_libre', 14, 2)->default(0);    // a favor de libre disponibilidad
            $t->string('estado', 12)->default('borrador');    // borrador | cerrada
            $t->json('detalle')->nullable();
            $t->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cerrada_en')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posiciones_iva');
        Schema::dropIfExists('conciliacion_reglas');
    }
};
