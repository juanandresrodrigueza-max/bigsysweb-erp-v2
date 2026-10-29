<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 40.5: avisos al celular (web push), novedades de BigSys con posponer y los "Me interesa" de la página de mejoras.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_suscripciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('endpoint'); $t->string('hash', 64)->unique();
            $t->string('p256dh', 200); $t->string('auth', 100);
            $t->string('dispositivo', 120)->nullable();
            $t->timestamp('ultimo_envio')->nullable();
            $t->timestamps();
        });
        Schema::create('novedades', function (Blueprint $t) {
            $t->id();
            $t->string('titulo', 150); $t->text('texto'); $t->string('url', 300)->nullable();
            $t->string('modulo', 40)->nullable(); // sólo a quien tiene (o no tiene) ese módulo
            $t->timestamp('publicada_en');
            $t->timestamps();
        });
        Schema::create('novedad_usuario', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('leida_en')->nullable(); $t->date('pospuesta_hasta')->nullable();
            $t->unique(['novedad_id', 'user_id']);
        });
        Schema::create('mejora_intereses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('modulo', 40); $t->string('comentario', 500)->nullable();
            $t->timestamp('atendido_en')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'modulo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mejora_intereses'); Schema::dropIfExists('novedad_usuario'); Schema::dropIfExists('novedades'); Schema::dropIfExists('push_suscripciones');
    }
};
