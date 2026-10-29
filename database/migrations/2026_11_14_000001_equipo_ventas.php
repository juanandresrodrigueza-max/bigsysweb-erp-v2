<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 29.6: avisos a los vendedores con vigencia, notas del cliente (la memoria del vínculo) y relevamiento con fotos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_vendedores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('vendedor_id')->nullable();   // null = todos
            $t->string('titulo', 120);
            $t->text('texto')->nullable();
            $t->boolean('urgente')->default(false);     // no se puede bajar de la pantalla
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();
            $t->boolean('activo')->default(true);
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('notas_cliente', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('texto', 500);
            $t->timestamp('listo_at')->nullable();
            $t->foreignId('listo_por')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('relevamientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->foreignId('vendedor_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('tipo', 20);                    // gondola | competencia | stock | otro
            $t->string('nota', 300)->nullable();
            $t->string('foto', 200);
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void { Schema::dropIfExists('relevamientos'); Schema::dropIfExists('notas_cliente'); Schema::dropIfExists('avisos_vendedores'); }
};
