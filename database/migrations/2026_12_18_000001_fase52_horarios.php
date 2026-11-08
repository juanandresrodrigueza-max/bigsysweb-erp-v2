<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 52: platos con horario (menú del día, menú ejecutivo al mediodía). El happy hour vive en businesses.gastronomia_config.
return new class extends Migration
{
    public function up(): void { Schema::table('products', fn(Blueprint $t) => $t->json('horario')->nullable()); }
    public function down(): void { Schema::table('products', fn(Blueprint $t) => $t->dropColumn('horario')); }
};
