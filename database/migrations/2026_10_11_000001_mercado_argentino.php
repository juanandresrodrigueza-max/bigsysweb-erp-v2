<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 21 · Mercado argentino: percepciones de IVA y Ganancias por cliente, remito con datos de transporte y COT, planes de cuotas, envíos de Mercado Libre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->boolean('percepcion_iva')->default(false)->after('exento_iibb');
            $t->boolean('percepcion_ganancias')->default(false)->after('percepcion_iva');
        });
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->string('transportista', 120)->nullable();
            $t->string('transportista_cuit', 13)->nullable();
            $t->string('patente', 12)->nullable();
            $t->unsignedInteger('bultos')->nullable();
            $t->decimal('peso_kg', 12, 2)->nullable();
            $t->string('domicilio_entrega', 200)->nullable();
            $t->string('cot', 40)->nullable(); // Código de Operación de Traslado (ARBA) del remito
        });
        Schema::table('businesses', fn(Blueprint $t) => $t->json('tarjetas')->nullable());
        Schema::table('pedidos_web', fn(Blueprint $t) => $t->json('envio_datos')->nullable());
    }

    public function down(): void
    {
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn(['percepcion_iva', 'percepcion_ganancias']));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['transportista', 'transportista_cuit', 'patente', 'bultos', 'peso_kg', 'domicilio_entrega', 'cot']));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('tarjetas'));
        Schema::table('pedidos_web', fn(Blueprint $t) => $t->dropColumn('envio_datos'));
    }
};
