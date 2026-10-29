<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 36.2 y 36.5: la recepción (lo que se vio al recibir el equipo, a mano o con IA) y cuándo tiene que volver.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ordenes_trabajo', 'recepcion')) Schema::table('ordenes_trabajo', function (Blueprint $t) {
            $t->json('recepcion')->nullable();   // combustible, luces, daños [{zona, detalle, foto_id}], checklist {item: verde|amarillo|rojo}, sugeridos [], comparacion
            $t->date('volver_el')->nullable();    // recordatorio por tiempo
            $t->decimal('volver_medidor', 14, 1)->nullable(); // o por medidor
            $t->timestamp('recordado_en')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ordenes_trabajo', 'recepcion')) Schema::table('ordenes_trabajo', fn(Blueprint $t) => $t->dropColumn(['recepcion', 'volver_el', 'volver_medidor', 'recordado_en']));
    }
};
