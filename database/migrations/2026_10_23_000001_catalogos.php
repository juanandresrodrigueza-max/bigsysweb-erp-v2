<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.3: catálogos para mandar a los clientes, por lista de precios y con la identidad de la empresa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('nombre', 120);
            $t->unsignedTinyInteger('lista')->default(1);
            $t->json('rubros')->nullable();              // null = todos
            $t->boolean('iva_incluido')->default(true);
            $t->boolean('mostrar_stock')->default(false);
            $t->boolean('solo_con_stock')->default(false);
            $t->boolean('mostrar_fotos')->default(true);
            $t->boolean('mostrar_codigo')->default(true);
            $t->string('nota', 500)->nullable();          // texto de portada (vigencia, condiciones)
            $t->string('token', 40)->unique();
            $t->unsignedInteger('vistas')->default(0);
            $t->timestamp('visto_en')->nullable();
            $t->boolean('activo')->default(true);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('catalogos'); }
};
