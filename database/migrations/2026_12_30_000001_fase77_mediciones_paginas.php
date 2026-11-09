<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 77: cada noche se miden las páginas clave de las empresas más grandes; acá queda la historia para ver si algo se pone lento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mediciones_paginas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->index();
            $t->string('url', 160);
            $t->unsignedInteger('ms');
            $t->unsignedInteger('consultas');
            $t->unsignedInteger('sql_ms')->default(0);
            $t->unsignedSmallInteger('http')->default(200);
            $t->json('lentas')->nullable(); // las 3 consultas más lentas
            $t->string('ronda', 20)->index(); // AAAA-MM-DD HH:MM de la corrida: agrupa las mediciones de una misma noche
            $t->timestamp('created_at')->useCurrent();
            $t->index(['url', 'created_at']);
        });
        // Índices que faltaban en tablas que crecen todos los días y se consultan por empresa y fecha.
        Schema::table('ia_llamadas', fn(Blueprint $t) => $t->index(['business_id', 'created_at'], 'ia_llamadas_business_created_idx'));
        Schema::table('envios', fn(Blueprint $t) => $t->index(['business_id', 'created_at'], 'envios_business_created_idx'));
    }

    public function down(): void
    {
        Schema::table('envios', fn(Blueprint $t) => $t->dropIndex('envios_business_created_idx'));
        Schema::table('ia_llamadas', fn(Blueprint $t) => $t->dropIndex('ia_llamadas_business_created_idx'));
        Schema::dropIfExists('mediciones_paginas');
    }
};
