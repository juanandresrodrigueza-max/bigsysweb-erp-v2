<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 10: dos factores, webhooks, tickets de soporte, backups, onboarding y mantenimiento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('onboarding_completado_en')->nullable();
            $table->json('onboarding')->nullable(); // pasos marcados a mano
            $table->boolean('backup_auto')->default(true);
        });
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->string('url', 500);
            $table->json('eventos');
            $table->string('secreto', 80);
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('fallos')->default(0);
            $table->timestamp('ultimo_envio_en')->nullable();
            $table->timestamps();
        });
        Schema::create('webhook_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->string('evento', 60);
            $table->json('payload');
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('respuesta')->nullable();
            $table->unsignedInteger('ms')->nullable();
            $table->timestamps();
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asunto', 150);
            $table->string('categoria', 30)->default('consulta'); // consulta | error | sugerencia | facturacion
            $table->string('prioridad', 10)->default('normal');   // baja | normal | alta
            $table->string('estado', 15)->default('abierto');     // abierto | respondido | cerrado
            $table->json('mensajes'); // [{de: 'empresa'|'soporte', usuario, texto, fecha}]
            $table->timestamp('ultimo_mensaje_en')->nullable();
            $table->timestamp('cerrado_en')->nullable();
            $table->timestamps();
        });
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('archivo', 200);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('origen', 15)->default('manual'); // manual | auto | pre_restauracion
            $table->json('resumen')->nullable(); // filas por tabla
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entidad', 30);
            $table->string('archivo', 200)->nullable();
            $table->unsignedInteger('leidas')->default(0);
            $table->unsignedInteger('creadas')->default(0);
            $table->unsignedInteger('actualizadas')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->json('detalle')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('webhook_entregas');
        Schema::dropIfExists('webhooks');
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['onboarding_completado_en', 'onboarding', 'backup_auto']));
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_enabled_at']));
    }
};
