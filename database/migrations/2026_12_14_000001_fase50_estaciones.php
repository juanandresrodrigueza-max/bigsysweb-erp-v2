<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 50: estaciones de cocina (cocina, barra, parrilla, postres): cada plato va a la suya, con su pantalla y su comanda impresa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->string('estacion', 30)->nullable());
        Schema::table('comanda_items', fn(Blueprint $t) => $t->string('estacion', 30)->nullable());
        Schema::table('businesses', fn(Blueprint $t) => $t->json('gastronomia_config')->nullable());
    }
    public function down(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('estacion'));
        Schema::table('comanda_items', fn(Blueprint $t) => $t->dropColumn('estacion'));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('gastronomia_config'));
    }
};
