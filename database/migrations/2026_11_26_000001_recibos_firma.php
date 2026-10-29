<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 38: cada recibo con su link, el envío, la vista y la firma del empleado (fecha, IP y navegador); y los recibos que vienen de afuera en PDF.
return new class extends Migration
{
    private function firma(Blueprint $t): void
    {
        $t->string('token', 48)->nullable()->index();
        $t->timestamp('enviado_en')->nullable();
        $t->timestamp('visto_en')->nullable();
        $t->timestamp('firmado_en')->nullable();
        $t->longText('firma')->nullable();
        $t->string('firma_ip', 45)->nullable();
        $t->string('firma_agente', 255)->nullable();
        $t->string('conformidad', 12)->nullable(); // conforme | disconforme
        $t->string('observacion', 500)->nullable();
    }

    public function up(): void
    {
        if (! Schema::hasColumn('liquidacion_items', 'token')) Schema::table('liquidacion_items', fn(Blueprint $t) => $this->firma($t));
        if (! Schema::hasTable('recibos_externos')) Schema::create('recibos_externos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
            $t->string('periodo', 7)->nullable();
            $t->string('archivo_origen');
            $t->string('hash_archivo', 40)->index();   // el PDF entero: no se sube dos veces aunque lo renombren
            $t->unsignedInteger('pagina');
            $t->string('hash_pagina', 40)->index();
            $t->string('path');
            $t->text('texto')->nullable();
            $t->string('identificado_por', 20)->nullable(); // cuil | dni | legajo | nombre | a_mano
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $this->firma($t);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos_externos');
        if (Schema::hasColumn('liquidacion_items', 'token')) Schema::table('liquidacion_items', fn(Blueprint $t) => $t->dropColumn(['token', 'enviado_en', 'visto_en', 'firmado_en', 'firma', 'firma_ip', 'firma_agente', 'conformidad', 'observacion']));
    }
};
