<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 36: el rubro del taller (palabras y datos de cada equipo), esperando con motivo, aprobación por ítem, técnicos por %, fotos y plantillas.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'servicios_config')) Schema::table('businesses', fn(Blueprint $t) => $t->json('servicios_config')->nullable());
        if (! Schema::hasColumn('ordenes_trabajo', 'datos_equipo')) Schema::table('ordenes_trabajo', function (Blueprint $t) {
            $t->json('datos_equipo')->nullable();
            $t->decimal('medidor', 14, 1)->nullable();
            $t->string('esperando_motivo', 160)->nullable();
            $t->timestamp('esperando_desde')->nullable();
            $t->json('aprobacion')->nullable();
        });
        if (! Schema::hasColumn('orden_trabajo_items', 'aprobado')) Schema::table('orden_trabajo_items', function (Blueprint $t) {
            $t->boolean('aprobado')->nullable();
            $t->boolean('pendiente_proxima')->default(false);
            $t->json('tecnicos')->nullable(); // [{user_id, pct}] para la mano de obra
            $t->unsignedBigInteger('viene_de_item_id')->nullable();
        });
        if (! Schema::hasTable('orden_trabajo_fotos')) Schema::create('orden_trabajo_fotos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('orden_trabajo_id')->constrained('ordenes_trabajo')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('momento', 12)->default('antes'); // recepcion | antes | durante | despues
            $t->string('path');
            $t->string('nota', 200)->nullable();
            $t->timestamps();
        });
        if (! Schema::hasTable('servicio_plantillas')) Schema::create('servicio_plantillas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('nombre', 120);
            $t->json('items');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_plantillas');
        Schema::dropIfExists('orden_trabajo_fotos');
        if (Schema::hasColumn('orden_trabajo_items', 'aprobado')) Schema::table('orden_trabajo_items', fn(Blueprint $t) => $t->dropColumn(['aprobado', 'pendiente_proxima', 'tecnicos', 'viene_de_item_id']));
        if (Schema::hasColumn('ordenes_trabajo', 'datos_equipo')) Schema::table('ordenes_trabajo', fn(Blueprint $t) => $t->dropColumn(['datos_equipo', 'medidor', 'esperando_motivo', 'esperando_desde', 'aprobacion']));
        if (Schema::hasColumn('businesses', 'servicios_config')) Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('servicios_config'));
    }
};
