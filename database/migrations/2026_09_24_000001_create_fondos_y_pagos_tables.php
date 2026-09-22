<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_fondos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo', 12); // caja | banco | billetera | tarjeta
            $table->string('nombre', 80);
            $table->string('banco', 60)->nullable();
            $table->string('cbu', 30)->nullable();
            $table->string('alias', 40)->nullable();
            $table->string('moneda', 3)->default('ARS');
            $table->decimal('saldo', 14, 2)->default(0);
            $table->decimal('saldo_minimo', 14, 2)->default(0);
            $table->boolean('activa')->default(true);
            $table->boolean('es_default')->default(false);
            $table->timestamps();
        });

        Schema::create('movimientos_fondos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuenta_fondos_id')->constrained('cuentas_fondos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('turno_caja_id')->nullable();
            $table->date('fecha');
            $table->string('origen', 20); // cobro | pago | gasto | ingreso | transferencia | ajuste | cheque | apertura
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('expense_category_id')->nullable();
            $table->string('concepto');
            $table->decimal('ingreso', 14, 2)->default(0);
            $table->decimal('egreso', 14, 2)->default(0);
            $table->string('referencia', 120)->nullable();
            $table->boolean('conciliado')->default(false);
            $table->timestamps();
            $table->index(['business_id', 'cuenta_fondos_id', 'fecha']);
            $table->index(['origen', 'origen_id']);
        });

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 8); // tercero | propio
            $table->string('numero', 30);
            $table->string('banco', 60)->nullable();
            $table->string('emisor', 120)->nullable();
            $table->string('cuit_emisor', 20)->nullable();
            $table->date('fecha_emision');
            $table->date('fecha_pago');
            $table->decimal('monto', 14, 2);
            $table->boolean('echeq')->default(false);
            $table->string('estado', 12)->default('cartera'); // cartera | depositado | entregado | cobrado | rechazado | anulado | pagado
            $table->foreignId('cobro_id')->nullable();
            $table->foreignId('pago_id')->nullable();
            $table->foreignId('cuenta_fondos_id')->nullable(); // banco donde se depositó / cuenta que lo emite
            $table->foreignId('contact_id')->nullable();
            $table->date('fecha_estado')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'estado', 'fecha_pago']);
        });

        Schema::create('turnos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuenta_fondos_id')->constrained('cuentas_fondos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('apertura');
            $table->timestamp('cierre')->nullable();
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->decimal('saldo_esperado', 14, 2)->nullable();
            $table->decimal('saldo_contado', 14, 2)->nullable();
            $table->decimal('diferencia', 14, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('numero');
            $table->date('fecha');
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('a_cuenta', 14, 2)->default(0);
            $table->string('estado', 10)->default('confirmado');
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'numero']);
        });

        Schema::create('pago_medios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_id')->constrained()->cascadeOnDelete();
            $table->string('medio', 20); // efectivo | transferencia | cheque_propio | cheque_tercero | retencion | tarjeta | billetera
            $table->decimal('monto', 14, 2);
            $table->foreignId('cuenta_fondos_id')->nullable();
            $table->foreignId('cheque_id')->nullable();
            $table->string('referencia', 120)->nullable();
            $table->json('datos')->nullable();
        });

        Schema::create('pago_imputaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->decimal('monto', 14, 2);
        });

        Schema::create('retenciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pago_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 12); // ganancias | iva | iibb | suss
            $table->string('jurisdiccion', 40)->nullable();
            $table->decimal('base', 14, 2);
            $table->decimal('alicuota', 6, 3);
            $table->decimal('monto', 14, 2);
            $table->string('certificado', 40)->nullable();
            $table->date('fecha');
            $table->timestamps();
        });

        Schema::table('cobro_medios', function (Blueprint $table) {
            $table->foreignId('cuenta_fondos_id')->nullable()->after('monto');
            $table->foreignId('cheque_id')->nullable()->after('cuenta_fondos_id');
        });

        Schema::table('cuenta_corriente', function (Blueprint $table) {
            $table->foreignId('pago_id')->nullable()->after('cobro_id');
        });

        Schema::table('comprobantes', function (Blueprint $table) {
            $table->string('numero_proveedor', 30)->nullable()->after('numero'); // "0003-00012345" tal como viene en la factura de compra
            $table->string('cae_proveedor', 20)->nullable()->after('numero_proveedor');
            $table->string('origen_carga', 12)->nullable()->after('cae_proveedor'); // manual | ocr | afip_csv
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['numero_proveedor', 'cae_proveedor', 'origen_carga']));
        Schema::table('cuenta_corriente', fn(Blueprint $t) => $t->dropColumn('pago_id'));
        Schema::table('cobro_medios', fn(Blueprint $t) => $t->dropColumn(['cuenta_fondos_id', 'cheque_id']));
        foreach (['retenciones', 'pago_imputaciones', 'pago_medios', 'pagos', 'turnos_caja', 'cheques', 'movimientos_fondos', 'cuentas_fondos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
