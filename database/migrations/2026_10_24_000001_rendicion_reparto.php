<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.5: rendición del reparto por chofer y viaje. Durante el viaje se anota lo cobrado en cada entrega;
// al volver se rinde: los cobros entran a la caja o al banco, los viáticos salen como gasto y la diferencia queda registrada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_entrega_cobros', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('orden_entrega_id')->index();
            $t->foreignId('orden_entrega_item_id')->nullable();
            $t->foreignId('comprobante_id')->nullable();
            $t->foreignId('contact_id');
            $t->string('medio', 16);                   // efectivo | cheque | transferencia | mercadopago
            $t->decimal('monto', 14, 2);
            $t->string('referencia', 80)->nullable();
            $t->json('datos')->nullable();             // cheque: banco, número, fecha de pago
            $t->foreignId('cobro_id')->nullable();     // recibo generado al rendir
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
        Schema::table('ordenes_entrega', function (Blueprint $t) {
            $t->timestamp('rendida_en')->nullable();
            $t->foreignId('rendida_por')->nullable();
            $t->foreignId('cuenta_fondos_id')->nullable(); // caja donde se rindió el efectivo
            $t->json('rendicion')->nullable();             // esperado por medio, contado, viáticos, diferencia
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_entrega_cobros');
        Schema::table('ordenes_entrega', fn(Blueprint $t) => $t->dropColumn(['rendida_en', 'rendida_por', 'cuenta_fondos_id', 'rendicion']));
    }
};
