<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 29.4: un descuento por encima del tope lo autoriza el encargado en el mismo teléfono del vendedor.
// La autorización vale 15 minutos, para una sola venta, y queda atada al vendedor, al cliente y al porcentaje.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones_descuento', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id');                 // el vendedor que la pidió
            $t->foreignId('autorizado_por');          // el encargado
            $t->foreignId('contact_id');
            $t->decimal('pct', 6, 2);
            $t->string('token', 48)->unique();
            $t->timestamp('vence_at');
            $t->timestamp('usada_at')->nullable();
            $t->foreignId('comprobante_id')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void { Schema::dropIfExists('autorizaciones_descuento'); }
};
