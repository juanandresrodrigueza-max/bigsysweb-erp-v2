<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sucursales con CUIT y facturación propios (cada una emite con su certificado ARCA) y vista consolidada para la casa central.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $t) {
            $t->string('cuit', 20)->nullable()->after('email');
            $t->string('razon_social', 160)->nullable()->after('cuit');
            $t->string('condicion_iva', 40)->nullable()->after('razon_social');
            $t->string('iibb', 40)->nullable()->after('condicion_iva');
            $t->date('inicio_actividades')->nullable()->after('iibb');
            $t->string('afip_cert_path')->nullable()->after('inicio_actividades');
            $t->string('afip_key_path')->nullable()->after('afip_cert_path');
            $t->boolean('afip_produccion')->nullable()->after('afip_key_path');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('ver_consolidado')->default(false)->after('current_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', fn(Blueprint $t) => $t->dropColumn(['cuit', 'razon_social', 'condicion_iva', 'iibb', 'inicio_actividades', 'afip_cert_path', 'afip_key_path', 'afip_produccion']));
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('ver_consolidado'));
    }
};
