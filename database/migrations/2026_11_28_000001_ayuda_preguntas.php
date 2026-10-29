<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 40.4: cada pregunta a la ayuda queda guardada con la respuesta, las fuentes y si le sirvió, para ver lo que el manual no tiene.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ayuda_preguntas')) Schema::create('ayuda_preguntas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('pregunta', 300);
            $t->text('respuesta')->nullable();
            $t->json('fuentes')->nullable();
            $t->boolean('sin_respuesta')->default(false);
            $t->boolean('util')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'sin_respuesta']);
        });
    }

    public function down(): void { Schema::dropIfExists('ayuda_preguntas'); }
};
