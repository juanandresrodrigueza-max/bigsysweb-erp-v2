<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 98 · Capital humano: legajo completo, ausencias y vacaciones (LCT), feriados propios, datos para el Libro de Sueldos Digital y ganancias 4ta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $t) {
            $t->date('fecha_nacimiento')->nullable()->after('documento');
            $t->string('domicilio', 160)->nullable()->after('fecha_nacimiento');
            $t->string('localidad', 80)->nullable()->after('domicilio');
            $t->string('estado_civil', 20)->nullable()->after('localidad');
            $t->unsignedTinyInteger('hijos')->default(0)->after('estado_civil');
            $t->string('contacto_emergencia', 120)->nullable()->after('hijos');
            $t->string('contacto_emergencia_tel', 40)->nullable()->after('contacto_emergencia');
            $t->decimal('vacaciones_ajuste', 6, 1)->default(0)->after('contacto_emergencia_tel'); // días a favor (+) o en contra (−) que se arrastran de años anteriores
            $t->json('lsd')->nullable()->after('vacaciones_ajuste');        // situación de revista, condición, modalidad de contratación, zona, siniestrado, adherentes
            $t->json('ganancias')->nullable()->after('lsd');                // deducciones del SIRADIG: cónyuge, hijos, alquiler, servicio doméstico, otras
        });
        Schema::create('feriados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->date('fecha');
            $t->string('nombre', 120);
            $t->string('tipo', 20)->default('propio'); // propio | puente | no_laborable | quitado (un nacional que esta empresa trabaja)
            $t->timestamps();
            $t->unique(['business_id', 'fecha']);
        });
        Schema::create('ausencias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $t->string('tipo', 30);
            $t->date('desde');
            $t->date('hasta');
            $t->decimal('dias', 5, 1)->default(0);
            $t->string('estado', 15)->default('aprobada'); // pendiente | aprobada | rechazada
            $t->unsignedSmallInteger('anio_vacaciones')->nullable(); // a qué año de vacaciones se imputa
            $t->boolean('certificado')->default(false);
            $t->text('notas')->nullable();
            $t->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['business_id', 'empleado_id', 'desde']);
            $t->index(['business_id', 'desde', 'hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ausencias');
        Schema::dropIfExists('feriados');
        Schema::table('empleados', fn(Blueprint $t) => $t->dropColumn(['fecha_nacimiento', 'domicilio', 'localidad', 'estado_civil', 'hijos', 'contacto_emergencia', 'contacto_emergencia_tel', 'vacaciones_ajuste', 'lsd', 'ganancias']));
    }
};
