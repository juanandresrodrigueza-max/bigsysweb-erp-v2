<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 103: a qué clientes se les hace comprobante interno por defecto (numeración propia, sin ARCA; mueve stock, cuenta corriente y plata).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', fn(Blueprint $t) => $t->boolean('comprobante_interno')->default(false)->after('recordar_precio'));
    }

    public function down(): void
    {
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn('comprobante_interno'));
    }
};
