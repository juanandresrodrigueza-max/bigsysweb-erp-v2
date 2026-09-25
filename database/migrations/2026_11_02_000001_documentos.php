<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Documentos adjuntos a clientes, proveedores y artículos (contratos, constancias, fichas técnicas, fotos…).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('adjuntable_type', 60);
            $t->unsignedBigInteger('adjuntable_id');
            $t->string('nombre', 150);
            $t->string('archivo');
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('tamano')->default(0);
            $t->date('fecha')->nullable();
            $t->string('notas', 300)->nullable();
            $t->timestamps();
            $t->index(['adjuntable_type', 'adjuntable_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('documentos'); }
};
