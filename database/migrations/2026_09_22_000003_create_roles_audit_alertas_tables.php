<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 50);
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->json('permisos'); // {"modulo": ["ver","crear",...]} o "*"
            $table->boolean('es_sistema')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'slug']);
        });

        Schema::create('user_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'business_location_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('accion', 40);
            $table->string('modelo', 80)->nullable();
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('descripcion')->nullable();
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
            $table->index(['modelo', 'modelo_id']);
        });

        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable();
            $table->string('modulo', 30);
            $table->string('tipo', 50);
            $table->string('severidad', 10)->default('aviso'); // info | aviso | critica
            $table->string('titulo');
            $table->text('detalle')->nullable();
            $table->string('url')->nullable();
            $table->string('modelo', 80)->nullable();
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->json('leida_por')->nullable();
            $table->timestamp('resuelta_en')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'resuelta_en']);
            $table->unique(['business_id', 'tipo', 'modelo', 'modelo_id'], 'alertas_unicas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_locations');
        Schema::dropIfExists('roles');
    }
};
