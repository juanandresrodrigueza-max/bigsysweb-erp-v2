<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 119.4: rutinas de Bigcito («todos los lunes a las 8 pasame el resumen»).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bigcito_rutinas')) return;
        Schema::create('bigcito_rutinas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('nombre', 120);
            $t->text('instruccion');
            $t->string('frecuencia', 20);          // cada_hora | diaria | habiles | semanal | mensual
            $t->json('dias')->nullable();          // semanal: 1 = lunes … 7 = domingo
            $t->unsignedTinyInteger('dia_mes')->nullable(); // mensual: 1 a 28, o 31 = último día
            $t->string('hora', 5)->default('08:00');
            $t->string('canal', 20)->default('aviso'); // aviso | whatsapp | mail
            $t->boolean('solo_si_hay')->default(false); // vigilancia: sólo avisa si hay algo
            $t->boolean('activa')->default(true);
            $t->timestamp('proxima_en')->nullable()->index();
            $t->timestamp('ultima_en')->nullable();
            $t->text('ultimo_resultado')->nullable();
            $t->json('propuestas')->nullable();    // ids de las propuestas que dejó la última corrida
            $t->unsignedInteger('ejecuciones')->default(0);
            $t->timestamps();
            $t->index(['business_id', 'user_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('bigcito_rutinas'); }
};
