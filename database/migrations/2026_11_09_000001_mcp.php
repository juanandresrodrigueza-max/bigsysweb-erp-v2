<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 28: el ERP como servidor MCP. Clientes OAuth (Claude, ChatGPT…), conexiones de cada usuario con su modo
// (solo lectura, acciones, acciones delicadas), propuestas de acción pendientes de confirmar y registro de cada llamada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clientes', function (Blueprint $t) {
            $t->id();
            $t->string('client_id', 64)->unique();
            $t->string('nombre', 150);
            $t->json('redirect_uris');
            $t->timestamps();
        });
        Schema::create('mcp_conexiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id')->index();
            $t->string('nombre', 150);
            $t->string('client_id', 64)->nullable();
            $t->foreignId('token_id')->nullable();
            $t->string('refresh_hash', 64)->nullable()->index();
            $t->string('modo', 12)->default('lectura'); // lectura | acciones | delicadas
            $t->timestamp('ultimo_uso')->nullable();
            $t->unsignedInteger('usos')->default(0);
            $t->timestamp('revocada_en')->nullable();
            $t->timestamps();
        });
        Schema::create('ia_propuestas', function (Blueprint $t) {
            $t->string('id', 26)->primary();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id');
            $t->foreignId('conexion_id')->nullable();
            $t->string('origen', 12); // asistente | mcp
            $t->string('herramienta', 60);
            $t->string('titulo', 300);
            $t->text('detalle')->nullable();
            $t->json('datos');
            $t->string('estado', 12)->default('pendiente'); // pendiente | ejecutada | cancelada | vencida | error
            $t->json('resultado')->nullable();
            $t->timestamp('expira_en');
            $t->timestamp('ejecutada_en')->nullable();
            $t->timestamps();
        });
        Schema::create('ia_llamadas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id');
            $t->foreignId('conexion_id')->nullable();
            $t->string('origen', 12);
            $t->string('herramienta', 60);
            $t->string('tipo', 10); // consulta | propuesta | ejecucion
            $t->boolean('ok')->default(true);
            $t->string('error', 300)->nullable();
            $t->unsignedInteger('ms')->default(0);
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['ia_llamadas', 'ia_propuestas', 'mcp_conexiones', 'mcp_clientes'] as $t) Schema::dropIfExists($t);
    }
};
