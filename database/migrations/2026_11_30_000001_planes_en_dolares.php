<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Planes con precio de lista en dólares (los pesos se calculan con la cotización del sistema),
// los extras de cada plan y el orden en que se muestran. Sueldos se contrata aparte por empresa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->decimal('precio_usd', 10, 2)->nullable();
            $t->decimal('precio_usd_anual', 10, 2)->nullable();
            $t->json('extras')->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('recomendado')->default(false);
        });
        Schema::table('businesses', fn(Blueprint $t) => $t->json('modulos_contratados')->nullable()); // extras fuera del plan, p. ej. sueldos
    }

    public function down(): void
    {
        Schema::table('plans', fn(Blueprint $t) => $t->dropColumn(['precio_usd', 'precio_usd_anual', 'extras', 'orden', 'recomendado']));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('modulos_contratados'));
    }
};
