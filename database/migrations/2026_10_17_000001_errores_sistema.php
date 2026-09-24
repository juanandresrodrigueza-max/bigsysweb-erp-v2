<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Errores del sistema agrupados (para el panel de salud, con o sin Sentry).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('errores_sistema', function (Blueprint $t) {
            $t->id();
            $t->string('hash', 40)->unique(); // clase + archivo + línea
            $t->string('clase', 190);
            $t->text('mensaje');
            $t->string('archivo', 255)->nullable();
            $t->unsignedInteger('linea')->nullable();
            $t->string('ruta', 255)->nullable();
            $t->string('metodo', 10)->nullable();
            $t->unsignedBigInteger('business_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedInteger('veces')->default(1);
            $t->timestamp('primera_vez')->nullable();
            $t->timestamp('ultima_vez')->nullable();
            $t->timestamp('resuelto_en')->nullable();
            $t->boolean('enviado_sentry')->default(false);
            $t->text('traza')->nullable();
            $t->timestamps();
            $t->index(['ultima_vez', 'resuelto_en']);
        });
    }

    public function down(): void { Schema::dropIfExists('errores_sistema'); }
};
