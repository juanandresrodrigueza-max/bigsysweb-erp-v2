<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 121: facturación electrónica como en el CRM. Un solo certificado de BigSys y cada empresa le delega el servicio
// desde su clave fiscal; el ERP habla directo con ARCA (WSAA + WSFEv1), sin librerías ni intermediarios.
return new class extends Migration
{
    public function up(): void
    {
        // El ticket de acceso de ARCA dura 12 horas y ARCA no entrega otro mientras siga vigente: se guarda en la base
        // (no en la caché, que se borra en cada actualización) y cifrado, porque token y sign son credenciales.
        Schema::create('arca_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('huella', 64);          // del certificado: un certificado nuevo no reusa el ticket del viejo
            $t->string('servicio', 40);
            $t->boolean('produccion');
            $t->text('token');
            $t->text('sign');
            $t->timestamp('expira');
            $t->timestamps();
            $t->unique(['huella', 'servicio', 'produccion']);
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->boolean('arca_bigsys')->default(false);       // factura por BigSys (delegación), sin certificado propio
            $t->timestamp('arca_verificada_en')->nullable();
        });
        Schema::table('business_locations', function (Blueprint $t) {
            $t->boolean('arca_bigsys')->default(false);       // sucursal con CUIT propio que también delegó en BigSys
            $t->timestamp('arca_verificada_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', fn (Blueprint $t) => $t->dropColumn(['arca_bigsys', 'arca_verificada_en']));
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn(['arca_bigsys', 'arca_verificada_en']));
        Schema::dropIfExists('arca_tickets');
    }
};
