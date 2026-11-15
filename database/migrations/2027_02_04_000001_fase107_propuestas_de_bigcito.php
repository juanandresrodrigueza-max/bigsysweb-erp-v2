<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 107: Bigcito trabaja de noche. Los agentes (cobrador, reposición, cierre del mes, precios, control interno) dejan
// propuestas; el dueño las aprueba o rechaza de a un toque. Aprobar ejecuta la acción con los servicios de siempre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propuestas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->string('agente', 20);            // cobrador | reposicion | cierre | precios | control
            $t->string('clave', 120);            // para no proponer dos veces lo mismo mientras está pendiente
            $t->string('titulo', 200);
            $t->text('detalle')->nullable();
            $t->json('datos')->nullable();       // lo que se muestra (renglones, evidencia)
            $t->json('accion')->nullable();      // qué hace aprobar: ['tipo' => ..., ...]; null = sólo enterarse
            $t->decimal('importe', 14, 2)->nullable();
            $t->unsignedTinyInteger('prioridad')->default(2); // 1 urgente, 2 normal, 3 cuando puedas
            $t->string('estado', 12)->default('pendiente');   // pendiente | aprobada | rechazada | vencida
            $t->text('resultado')->nullable();
            $t->string('motivo', 200)->nullable();
            $t->timestamp('resuelta_en')->nullable();
            $t->unsignedBigInteger('resuelta_por')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'estado']);
            $t->index(['business_id', 'agente', 'clave']);
        });
        Schema::table('businesses', fn(Blueprint $t) => $t->json('agentes')->nullable()->after('avisos'));
    }

    public function down(): void
    {
        Schema::dropIfExists('propuestas');
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('agentes'));
    }
};
