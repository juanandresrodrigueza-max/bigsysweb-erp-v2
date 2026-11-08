<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 56: opiniones de los clientes (encuesta al pagar) y la carta traducida.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resto_opiniones', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('comanda_id')->nullable()->constrained('comandas')->nullOnDelete(); $t->foreignId('mesa_id')->nullable(); $t->foreignId('user_id')->nullable();
            $t->unsignedTinyInteger('puntaje'); $t->text('comentario')->nullable(); $t->string('canal', 20)->default('qr'); $t->timestamps();
            $t->unique('comanda_id');
        });
        Schema::table('products', fn(Blueprint $t) => $t->json('traducciones')->nullable());
    }
    public function down(): void { Schema::dropIfExists('resto_opiniones'); Schema::table('products', fn(Blueprint $t) => $t->dropColumn('traducciones')); }
};
