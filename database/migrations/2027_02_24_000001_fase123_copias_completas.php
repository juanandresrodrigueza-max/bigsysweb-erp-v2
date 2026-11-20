<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 123: copia completa de la base de datos (todas las empresas y lo general del sistema), cifrada y fuera del servidor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copias_completas', function (Blueprint $t) {
            $t->id();
            $t->string('origen', 10)->default('auto'); // auto | manual
            $t->string('estado', 10)->default('haciendo'); // haciendo | ok | error
            $t->string('motor', 10)->nullable();
            $t->string('archivo')->nullable();      // copia cifrada local (se borra a los 2 días)
            $t->string('env_archivo')->nullable();  // la configuración del servidor (.env), cifrada con la misma clave
            $t->unsignedBigInteger('bytes')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->unsignedInteger('tablas')->nullable();
            $t->string('remoto')->nullable();       // disco:ruta fuera del servidor
            $t->string('remoto_env')->nullable();
            $t->boolean('verificada')->default(false);
            $t->boolean('podada')->default(false);  // ya no está fuera del servidor (vieja)
            $t->text('error')->nullable();
            $t->unsignedInteger('segundos')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('copias_completas'); }
};
