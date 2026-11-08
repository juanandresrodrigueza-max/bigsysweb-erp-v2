<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 59: el texto de cada documento adjunto, para buscarlo y preguntarle.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) { $t->longText('texto')->nullable(); $t->timestamp('indexado_en')->nullable(); });
    }
    public function down(): void { Schema::table('documentos', fn(Blueprint $t) => $t->dropColumn(['texto', 'indexado_en'])); }
};
