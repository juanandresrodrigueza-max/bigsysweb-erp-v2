<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 37: el viaje del camión (paradas con cantidades parciales, acopios y paradas a mano; recorrido; links del chofer y del cliente)
// y los pases entre sucursales que se cuentan al salir y al llegar.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_locations', 'lat')) Schema::table('business_locations', function (Blueprint $t) { $t->decimal('lat', 10, 7)->nullable(); $t->decimal('lng', 10, 7)->nullable(); });
        if (! Schema::hasColumn('ordenes_entrega', 'token_chofer')) Schema::table('ordenes_entrega', function (Blueprint $t) {
            $t->string('token_chofer', 48)->nullable()->index();
            $t->timestamp('salida_en')->nullable();
            $t->decimal('ultima_lat', 10, 7)->nullable();
            $t->decimal('ultima_lng', 10, 7)->nullable();
            $t->timestamp('ultima_pos_en')->nullable();
            $t->decimal('origen_lat', 10, 7)->nullable();
            $t->decimal('origen_lng', 10, 7)->nullable();
        });
        if (! Schema::hasColumn('orden_entrega_items', 'tipo')) Schema::table('orden_entrega_items', function (Blueprint $t) {
            $t->string('tipo', 12)->default('comprobante'); // comprobante | acopio | parada
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('acopio_id')->nullable();
            $t->string('nombre', 120)->nullable();       // parada a mano
            $t->json('cantidades')->nullable();          // {item_id: cantidad} si va una parte
            $t->string('token', 48)->nullable()->index(); // link del cliente
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('recibio', 120)->nullable();
            $t->string('foto_path')->nullable();
            $t->string('motivo', 200)->nullable();
        });
        // Las paradas a mano y los retiros de acopio no tienen comprobante.
        Schema::table('orden_entrega_items', fn(Blueprint $t) => $t->unsignedBigInteger('comprobante_id')->nullable()->change());
        if (! Schema::hasColumn('transferencias_stock', 'enviada_en')) Schema::table('transferencias_stock', function (Blueprint $t) {
            $t->timestamp('enviada_en')->nullable();
            $t->timestamp('recibida_en')->nullable();
            $t->unsignedBigInteger('recibida_por')->nullable();
            $t->string('token', 48)->nullable()->index();
        });
        if (! Schema::hasColumn('transferencia_stock_items', 'cantidad_recibida')) Schema::table('transferencia_stock_items', fn(Blueprint $t) => $t->decimal('cantidad_recibida', 14, 3)->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('transferencia_stock_items', 'cantidad_recibida')) Schema::table('transferencia_stock_items', fn(Blueprint $t) => $t->dropColumn('cantidad_recibida'));
        if (Schema::hasColumn('transferencias_stock', 'enviada_en')) Schema::table('transferencias_stock', fn(Blueprint $t) => $t->dropColumn(['enviada_en', 'recibida_en', 'recibida_por', 'token']));
        if (Schema::hasColumn('orden_entrega_items', 'tipo')) Schema::table('orden_entrega_items', fn(Blueprint $t) => $t->dropColumn(['tipo', 'contact_id', 'acopio_id', 'nombre', 'cantidades', 'token', 'lat', 'lng', 'recibio', 'foto_path', 'motivo']));
        if (Schema::hasColumn('ordenes_entrega', 'token_chofer')) Schema::table('ordenes_entrega', fn(Blueprint $t) => $t->dropColumn(['token_chofer', 'salida_en', 'ultima_lat', 'ultima_lng', 'ultima_pos_en', 'origen_lat', 'origen_lng']));
        if (Schema::hasColumn('business_locations', 'lat')) Schema::table('business_locations', fn(Blueprint $t) => $t->dropColumn(['lat', 'lng']));
    }
};
