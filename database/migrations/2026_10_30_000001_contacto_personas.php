<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Personas de contacto de un cliente o proveedor (titular, compras, pagos…) y qué documentos recibe cada una.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacto_personas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->string('nombre', 100);
            $t->string('cargo', 80)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('notas', 500)->nullable();
            $t->boolean('recibe_comprobantes')->default(false);
            $t->boolean('recibe_cobranzas')->default(false);
            $t->boolean('recibe_pagos')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('contacto_personas'); }
};
