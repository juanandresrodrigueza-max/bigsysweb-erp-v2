<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 89: aviso en la campanita: plano del salón, asientos, cortesías, arqueo ciego y mermas.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Salón con plano, asientos y cortesías')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Salón con plano, asientos y cortesías', 'modulo' => 'gastronomia', 'url' => '/gastronomia',
            'texto' => 'Big Restó: el salón ahora es un plano (acomodá las mesas arrastrándolas, con su forma), cada plato se puede pedir para un asiento y al cobrar se separa solo, y un plato se puede marcar como cortesía (invitación, consumo del personal, error de cocina) sin cobrarlo y con su costo registrado. En Stock hay una sección nueva, Mermas y cortesías: lo que se tira, se rompe o vence, con motivo y costo; a Bigcito le podés decir "tiré dos kilos de tomate". Y en Plata → Cerrar caja, el encargado puede activar el arqueo ciego: el cajero cuenta sin ver lo esperado.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Salón con plano, asientos y cortesías')->delete(); }
};
