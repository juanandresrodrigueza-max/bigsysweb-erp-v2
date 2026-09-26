<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 27.5: contenido neto del envase, para mostrar el precio por kilo o por litro en la góndola.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('contenido_neto', 12, 3)->nullable();
            $t->string('contenido_unidad', 4)->nullable(); // g, kg, ml, l, un
        });
    }

    public function down(): void { Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['contenido_neto', 'contenido_unidad'])); }
};
