<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 104: la numeración interna usa claves como «NCA-X» (5 letras) y la columna admitía 4: en PostgreSQL fallaba la nota de crédito interna.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('punto_venta_numeros', fn(Blueprint $t) => $t->string('tipo', 12)->change());
    }

    public function down(): void
    {
        Schema::table('punto_venta_numeros', fn(Blueprint $t) => $t->string('tipo', 4)->change());
    }
};
