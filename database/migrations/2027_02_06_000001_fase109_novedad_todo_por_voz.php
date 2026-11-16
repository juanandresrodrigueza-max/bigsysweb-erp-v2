<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 109: aviso en la campanita (y en la página de ventas): todo lo del día se puede pedir hablando.
return new class extends Migration
{
    private const TITULO = 'Todo por voz: cambiar, dar de baja, cargar compras, notas de crédito, usuarios, turnos y la caja';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'dashboard', 'url' => '/ayuda/alertas-y-asistente',
            'texto' => 'Si lo podés hacer en pantalla, se lo podés pedir a Bigcito: «cambiale el teléfono a López», «dá de baja el artículo Tornillo», «cargá la factura A de Aceros Sur», «hacé la nota de crédito de la 27 por 10.000», «creá el usuario Caja Uno», «agendá a Gómez el martes a las 10», «cerrá la caja con 85.300». Siempre te muestra la propuesta y lo hace cuando decís que sí, con tu permiso. Preguntale «¿qué podés hacer?».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
