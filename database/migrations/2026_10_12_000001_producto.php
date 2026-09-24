<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 22 · Producto: tour visto por usuario, contador con varias empresas y métricas de uso por día.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn(Blueprint $t) => $t->timestamp('tour_visto_en')->nullable());
        Schema::create('user_businesses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'business_id']);
        });
        Schema::create('uso_diario', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('fecha');
            $t->string('modulo', 30);
            $t->unsignedInteger('vistas')->default(0);
            $t->unsignedInteger('acciones')->default(0);
            $t->unique(['business_id', 'user_id', 'fecha', 'modulo'], 'uso_diario_unico');
            $t->index(['business_id', 'fecha']);
            $t->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uso_diario');
        Schema::dropIfExists('user_businesses');
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('tour_visto_en'));
    }
};
