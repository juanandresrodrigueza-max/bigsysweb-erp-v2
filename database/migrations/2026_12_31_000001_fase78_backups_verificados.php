<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 78: cada copia se verifica (se abre y se comparan conteos), se ensaya su restauración y se sube fuera del servidor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $t) {
            $t->timestamp('verificado_en')->nullable();
            $t->json('verificacion')->nullable();   // {ok, tablas, problemas[]}
            $t->timestamp('ensayo_en')->nullable();  // última restauración de ensayo (en una transacción que se deshace)
            $t->boolean('ensayo_ok')->nullable();
            $t->string('remoto', 200)->nullable();   // disco:ruta de la copia fuera del servidor
            $t->timestamp('remoto_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', fn(Blueprint $t) => $t->dropColumn(['verificado_en', 'verificacion', 'ensayo_en', 'ensayo_ok', 'remoto', 'remoto_en']));
    }
};
