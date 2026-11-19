<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 120.6: encargos entre personas del equipo. Y avisos de la campana dirigidos a una sola persona.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encargos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('de_user_id');
            $t->foreignId('para_user_id')->index();
            $t->string('texto', 300);
            $t->text('detalle')->nullable();
            $t->string('url', 250)->nullable();                 // la pantalla relacionada, si hay
            $t->timestamp('vence_en')->nullable();
            $t->string('estado', 10)->default('pendiente')->index(); // pendiente | hecho | cancelado
            $t->timestamp('hecho_en')->nullable();
            $t->string('nota', 300)->nullable();                // lo que contestó quien lo hizo
            $t->string('origen', 10)->default('pantalla');      // pantalla | bigcito | whatsapp
            $t->string('aviso', 10)->nullable();                // por dónde se le avisó: whatsapp | campana
            $t->timestamp('recordado_en')->nullable();
            $t->timestamps();
        });
        Schema::table('alertas', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->index();      // null = para todos los que ven ese módulo
        });
    }

    public function down(): void
    {
        Schema::table('alertas', fn (Blueprint $t) => $t->dropColumn('user_id'));
        Schema::dropIfExists('encargos');
    }
};
