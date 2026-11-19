<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 120.3: la casilla de facturas. Lo que llega por mail (o se sube de a varias) se lee solo y queda para revisar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas_entrantes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->index();   // null = no se pudo saber de qué empresa era
            $t->string('origen', 12)->default('mail');           // mail | subida
            $t->string('remitente', 190)->nullable();
            $t->string('asunto', 200)->nullable();
            $t->string('archivo', 200)->nullable();              // nombre del adjunto
            $t->string('ruta', 250)->nullable();                 // dónde quedó guardado
            $t->string('mime', 60)->nullable();
            $t->char('hash', 40)->nullable()->index();
            $t->string('message_id', 250)->nullable()->index();
            $t->string('estado', 14)->default('lista')->index(); // lista | cargada | duplicada | sin_leer | sin_empresa | sin_adjunto | descartada
            $t->string('detalle', 300)->nullable();
            $t->foreignId('borrador_id')->nullable();
            $t->foreignId('compra_id')->nullable();
            $t->string('proveedor', 120)->nullable();
            $t->string('numero', 30)->nullable();
            $t->decimal('total', 14, 2)->nullable();
            $t->timestamps();
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->string('casilla_token', 16)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('casilla_token'));
        Schema::dropIfExists('facturas_entrantes');
    }
};
