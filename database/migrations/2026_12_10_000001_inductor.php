<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 48: el inductor de Bigcito. Lo que contestó la empresa y los módulos que dijo que no usa (se ocultan del menú).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $t) { $t->json('induccion')->nullable(); $t->json('modulos_ocultos')->nullable(); });
    }
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $t) { $t->dropColumn(['induccion', 'modulos_ocultos']); });
    }
};
