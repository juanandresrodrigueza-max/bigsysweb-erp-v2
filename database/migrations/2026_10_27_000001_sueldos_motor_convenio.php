<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sueldos como los liquida un estudio: conceptos con código, cálculo por división / porcentaje / fijo sobre bases
// configurables (REM, NOREM, BASICO o códigos), cantidades de las novedades (días, feriados, vacaciones, años),
// jornada parcial, base de jornada completa (obra social), detracción de contribuciones y conceptos asignados por empleado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sueldo_conceptos', function (Blueprint $t) {
            $t->string('cantidad', 20)->nullable();                // novedad que da la cantidad: dias | feriados | vacaciones | anios | null (=1)
            $t->boolean('por_anio')->default(false);               // el valor se multiplica por los años de antigüedad
            $t->decimal('mas_antiguedad', 6, 3)->default(0);       // suma fija que además crece X % por año (acuerdos)
            $t->boolean('proporcional_jornada')->default(false);   // fijo × jornada (media jornada cobra la mitad)
            $t->boolean('jornada_completa')->default(false);       // la base se lleva a jornada completa (obra social)
            $t->boolean('con_detraccion')->default(false);         // contribuciones: base menos la detracción × jornada
            $t->string('grupo', 20)->nullable();                   // seg_social | obra_social | art | sindicato | otro
            $t->boolean('solo_asignados')->default(false);         // se aplica solo a los empleados que lo tienen asignado
            $t->string('etiqueta', 30)->nullable();                // lo que se imprime en "unidad" (ej. "8.33 %")
        });
        // La base pasa de basico|bruto a una expresión: BASICO, REM, NOREM o códigos sumados con "+".
        Schema::table('sueldo_conceptos', fn(Blueprint $t) => $t->string('base', 120)->default('BASICO')->change());
        DB::table('sueldo_conceptos')->where('base', 'basico')->update(['base' => 'BASICO']);
        DB::table('sueldo_conceptos')->where('base', 'bruto')->update(['base' => 'REM']);
        DB::table('sueldo_conceptos')->where('codigo', 'ANT')->update(['por_anio' => true]);

        Schema::table('empleados', function (Blueprint $t) {
            $t->string('documento', 20)->nullable();
            $t->string('centro_costo', 60)->nullable();
            $t->string('lugar_trabajo', 60)->nullable();
            $t->decimal('jornada', 4, 3)->default(1);              // 1 = completa, 0.5 = media jornada
        });
        Schema::create('empleado_conceptos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $t->foreignId('sueldo_concepto_id')->constrained('sueldo_conceptos')->cascadeOnDelete();
            $t->decimal('valor', 14, 4)->nullable();               // valor propio del empleado (si no, el del concepto)
            $t->unique(['empleado_id', 'sueldo_concepto_id']);
        });
        Schema::table('liquidacion_items', function (Blueprint $t) {
            $t->unsignedSmallInteger('feriados')->default(0);
            $t->unsignedSmallInteger('vacaciones')->default(0);
            $t->decimal('redondeo', 8, 2)->default(0);
        });
        Schema::table('liquidaciones', function (Blueprint $t) {
            $t->date('deposito_fecha')->nullable();                // último depósito de aportes (art. 140 LCT)
            $t->string('deposito_banco', 60)->nullable();
            $t->string('deposito_periodo', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_conceptos');
        Schema::table('liquidaciones', fn(Blueprint $t) => $t->dropColumn(['deposito_fecha', 'deposito_banco', 'deposito_periodo']));
        Schema::table('liquidacion_items', fn(Blueprint $t) => $t->dropColumn(['feriados', 'vacaciones', 'redondeo']));
        Schema::table('empleados', fn(Blueprint $t) => $t->dropColumn(['documento', 'centro_costo', 'lugar_trabajo', 'jornada']));
        Schema::table('sueldo_conceptos', fn(Blueprint $t) => $t->dropColumn(['cantidad', 'por_anio', 'mas_antiguedad', 'proporcional_jornada', 'jornada_completa', 'con_detraccion', 'grupo', 'solo_asignados', 'etiqueta']));
    }
};
