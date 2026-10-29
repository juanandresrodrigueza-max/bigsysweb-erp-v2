<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 29.5: cobrar en la calle (recibo firmado, plata a nombre del vendedor hasta que rinde), rendición recibida por el
// encargado, devoluciones y confirmación de entrega con nombre, firma y foto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_fondos', fn(Blueprint $t) => $t->foreignId('vendedor_id')->nullable()->index()); // "En mano · Vito": lo cobrado en la calle hasta que rinde
        Schema::table('cobros', function (Blueprint $t) {
            $t->text('firma')->nullable();
            $t->string('firma_nombre', 120)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('modo', 12)->nullable();          // calle
            $t->foreignId('rendicion_id')->nullable()->index();
        });
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->string('entrega_nombre', 120)->nullable();
            $t->text('entrega_firma')->nullable();
            $t->string('entrega_foto', 200)->nullable();
            $t->timestamp('entregado_at')->nullable();
            $t->foreignId('entregado_por')->nullable();
            $t->string('motivo_devolucion', 30)->nullable();
        });
        Schema::create('rendiciones_calle', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->unsignedInteger('numero');
            $t->foreignId('vendedor_id')->nullable()->index();
            $t->foreignId('user_id');                   // quién rindió
            $t->decimal('efectivo', 14, 2)->default(0);
            $t->decimal('transferencias', 14, 2)->default(0);
            $t->decimal('cheques', 14, 2)->default(0);
            $t->unsignedInteger('recibos')->default(0);
            $t->string('estado', 12)->default('rendida'); // rendida | recibida
            $t->decimal('efectivo_contado', 14, 2)->nullable();
            $t->decimal('diferencia', 14, 2)->nullable();
            $t->foreignId('caja_id')->nullable();
            $t->foreignId('banco_id')->nullable();
            $t->foreignId('recibida_por')->nullable();
            $t->timestamp('recibida_at')->nullable();
            $t->string('notas', 300)->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendiciones_calle');
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['entrega_nombre', 'entrega_firma', 'entrega_foto', 'entregado_at', 'entregado_por', 'motivo_devolucion']));
        Schema::table('cobros', fn(Blueprint $t) => $t->dropColumn(['firma', 'firma_nombre', 'lat', 'lng', 'modo', 'rendicion_id']));
        Schema::table('cuentas_fondos', fn(Blueprint $t) => $t->dropColumn('vendedor_id'));
    }
};
