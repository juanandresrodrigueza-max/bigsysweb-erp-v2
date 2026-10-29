<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 39: perfiles listos para las empresas que ya existen, conjuntos de empresas y la marca de estudio contable.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conjuntos_empresas')) Schema::create('conjuntos_empresas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('nombre', 80);
            $t->json('empresas');
            $t->timestamps();
        });
        if (! Schema::hasColumn('businesses', 'estudio')) Schema::table('businesses', fn(Blueprint $t) => $t->json('estudio')->nullable()); // marca, tramos de precio, vencimientos
        foreach (\Illuminate\Support\Facades\DB::table('businesses')->pluck('id') as $id) \App\Models\Role::crearRolesSistema((int) $id);
    }

    public function down(): void
    {
        Schema::dropIfExists('conjuntos_empresas');
        if (Schema::hasColumn('businesses', 'estudio')) Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('estudio'));
    }
};
