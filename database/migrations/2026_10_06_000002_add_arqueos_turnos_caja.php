<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Arqueos parciales durante el turno (contar la caja sin cerrarla) y retiros a tesorería.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos_caja', fn(Blueprint $t) => $t->json('arqueos')->nullable());
    }

    public function down(): void
    {
        Schema::table('turnos_caja', fn(Blueprint $t) => $t->dropColumn('arqueos'));
    }
};
