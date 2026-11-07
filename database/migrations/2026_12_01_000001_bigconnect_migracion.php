<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 41: la conexión con BIG Connect para traer los datos del cliente de BigSys (dirección del Hub y clave de API, cifrada).
return new class extends Migration
{
    public function up(): void { Schema::table('businesses', fn(Blueprint $t) => $t->text('bigconnect')->nullable()); }
    public function down(): void { Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('bigconnect')); }
};
