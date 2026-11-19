<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 120.1: la «ventana de 24 horas» de WhatsApp por número. Meta sólo deja mandar texto libre por la API a quien escribió
// en las últimas 24 horas; fuera de eso hay que usar una plantilla aprobada. Acá se anota cuándo escribió cada número
// y qué quedó pendiente de entregarle cuando vuelva a escribir.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_ventanas')) return;
        Schema::create('whatsapp_ventanas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->string('telefono', 20);            // los últimos 10 dígitos (sin 54 ni 9)
            $t->timestamp('ultimo_entrante_en')->nullable();
            $t->json('pendientes')->nullable();    // textos completos que se entregan cuando vuelve a escribir
            $t->json('ultimo_saliente')->nullable(); // {wamid, texto, en}: para reintentar con plantilla si Meta lo rebota
            $t->timestamps();
            $t->unique(['business_id', 'telefono']);
        });
    }

    public function down(): void { Schema::dropIfExists('whatsapp_ventanas'); }
};
