<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 61: atributos del artículo para publicar (título de publicación y atributos de MercadoLibre), completados por IA.
return new class extends Migration
{
    public function up(): void { Schema::table('products', fn(Blueprint $t) => $t->json('atributos')->nullable()); }
    public function down(): void { Schema::table('products', fn(Blueprint $t) => $t->dropColumn('atributos')); }
};
