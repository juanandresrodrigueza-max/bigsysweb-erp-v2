<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 68: bitácora de lo que viaja entre el ERP y el CRM, en los dos sentidos, para la salud de la conexión.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_eventos', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->string('direccion', 10); // salida (ERP → CRM) | entrada (CRM → ERP)
            $t->string('evento', 60); $t->string('referencia', 120)->nullable();
            $t->string('estado', 12); // ok | error | rechazado | ignorado
            $t->unsignedSmallInteger('http')->nullable(); $t->unsignedInteger('ms')->nullable(); $t->string('detalle', 300)->nullable();
            $t->timestamp('created_at');
            $t->index(['business_id', 'created_at']); $t->index(['business_id', 'estado']);
        });
    }
    public function down(): void { Schema::dropIfExists('crm_eventos'); }
};
