<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 29: una sola pantalla de venta (calle, mostrador, caja). Ruta del día por agenda del cliente, visitas selladas con ubicación
// y la configuración de cómo se vende en la calle.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->json('visitas')->nullable();          // agenda: {lun: 'man'|'tar'|'dia'|'1er'|'2do'|'3er'|'ult', ...}
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
        });
        Schema::table('vendedores', function (Blueprint $t) {
            $t->string('modo_default', 12)->default('mostrador'); // calle | mostrador | caja
            $t->decimal('objetivo_mensual', 14, 2)->nullable();
        });
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->string('modo_venta', 12)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
        });
        Schema::table('businesses', fn(Blueprint $t) => $t->json('ventas_config')->nullable());
        Schema::create('visitas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->foreignId('vendedor_id')->nullable()->index();
            $t->foreignId('user_id')->nullable();
            $t->string('tipo', 12);                  // llegue | no_vendi | venta
            $t->string('motivo', 30)->nullable();    // no_vendi: cerrado | sin_encargado | tiene_mercaderia | no_quiso | no_pudo_pagar | otro
            $t->string('nota', 300)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->foreignId('comprobante_id')->nullable();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas');
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('ventas_config'));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['modo_venta', 'lat', 'lng']));
        Schema::table('vendedores', fn(Blueprint $t) => $t->dropColumn(['modo_default', 'objetivo_mensual']));
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn(['visitas', 'lat', 'lng']));
    }
};
