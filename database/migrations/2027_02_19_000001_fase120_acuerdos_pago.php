<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 120.5: Bigcito cobrador. El plan que un cliente aceptó por WhatsApp, a la espera de que la empresa lo apruebe.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acuerdos_pago', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->string('telefono', 30)->nullable();
            $t->string('estado', 12)->default('pendiente')->index(); // pendiente | aprobado | rechazado
            $t->decimal('deuda', 14, 2);
            $t->unsignedSmallInteger('cuotas');
            $t->decimal('interes_pct', 6, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->decimal('cuota', 14, 2);
            $t->date('primer_vencimiento');
            $t->json('comprobantes');                 // ids de las facturas vencidas que entran al plan
            $t->json('charla')->nullable();           // lo que se dijeron, para que el dueño lo vea antes de aprobar
            $t->foreignId('plan_pago_id')->nullable();
            $t->foreignId('resuelto_por')->nullable();
            $t->timestamp('resuelto_en')->nullable();
            $t->string('motivo', 200)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('acuerdos_pago'); }
};
