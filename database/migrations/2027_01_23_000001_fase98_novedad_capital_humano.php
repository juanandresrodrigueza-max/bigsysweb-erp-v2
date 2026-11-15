<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 98: aviso en la campanita: ausencias y vacaciones, feriados, Libro de Sueldos Digital y ganancias.
return new class extends Migration
{
    private const TITULO = 'Sueldos: ausencias y vacaciones, feriados, Libro de Sueldos Digital y ganancias';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'sueldos', 'url' => '/sueldos/personal',
            'texto' => 'Sueldos → Ausencias y vacaciones: cargás vacaciones, licencias y faltas (los días se cuentan solos y no se pisan), el sistema calcula los días de vacaciones por antigüedad como dice la LCT y muestra el saldo de cada uno. El calendario de feriados se arma solo (nacionales más los propios y puentes). Al liquidar, las novedades del mes ya vienen con feriados, vacaciones y faltas. En la ficha del empleado hay más datos del legajo y los de revista. Con la liquidación confirmada, botón «Libro de Sueldos Digital» (archivo de ARCA). En Preferencias de sueldos, el contador carga la tabla de ganancias 4ta y el recibo retiene y devuelve solo. A Bigcito: «cargale vacaciones a Juan del 5 al 19», «¿cuántos días le quedan a María?», «¿quién falta hoy?», «¿cuándo es el próximo feriado?».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
