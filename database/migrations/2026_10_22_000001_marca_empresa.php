<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.4: identidad de la empresa en los comprobantes (logo, colores, datos extra) y los datos fiscales
// que exige una factura impresa (domicilio comercial, IIBB, inicio de actividades).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $t) {
            $t->string('address')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 100)->nullable();
            $t->string('iibb', 30)->nullable();
            $t->date('inicio_actividades')->nullable();
            $t->json('marca')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['address', 'city', 'province', 'iibb', 'inicio_actividades', 'marca']));
    }
};
