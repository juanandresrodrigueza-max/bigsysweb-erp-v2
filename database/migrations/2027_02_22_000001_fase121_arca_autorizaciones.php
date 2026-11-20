<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 121.1: constancia de que la empresa autorizó a BigSys a emitir comprobantes en su nombre: quién, cuándo, desde dónde
// y con qué texto exacto. Se guarda aparte de la empresa, y no se pisa: cada activación es una fila; al apagarla se marca revocada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arca_autorizaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('sucursal_id')->nullable();          // sucursal con CUIT propio (no se llama business_location_id para que no se complete sola con la del usuario)
            $t->foreignId('user_id')->nullable();
            $t->string('usuario', 150)->nullable();         // nombre y mail en el momento: si el usuario se borra, la constancia queda
            $t->string('cuit', 13);
            $t->string('razon_social', 150)->nullable();
            $t->string('representante', 200)->nullable();   // a quién se autorizó (CUIT y razón social de BigSys)
            $t->string('version', 20);
            $t->text('texto');                              // el texto exacto que se aceptó
            $t->string('ip', 45)->nullable();
            $t->string('navegador', 300)->nullable();
            $t->timestamp('aceptada_en');
            $t->timestamp('revocada_en')->nullable();
            $t->string('revocada_por', 150)->nullable();
            $t->string('motivo_revocacion', 200)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('arca_autorizaciones'); }
};
