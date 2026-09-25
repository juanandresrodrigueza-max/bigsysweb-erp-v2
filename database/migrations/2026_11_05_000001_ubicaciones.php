<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 27.4: gestión de almacén. Ubicaciones dentro de cada depósito (pasillo, estante, nivel) con su código de barras,
// y qué hay en cada una (artículo y, si tiene, lote). Lo que no está en ninguna ubicación queda "sin ubicar".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('deposito_id')->index();
            $t->string('codigo', 40);            // lo que lee la pistola: A-01-03
            $t->string('pasillo', 20)->nullable();
            $t->string('estante', 20)->nullable();
            $t->string('nivel', 20)->nullable();
            $t->string('tipo', 20)->default('estanteria'); // estanteria | piso | frio | cuarentena | recepcion | despacho
            $t->unsignedInteger('orden')->default(0);     // recorrido para preparar pedidos
            $t->boolean('activa')->default(true);
            $t->string('notas', 200)->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'codigo']);
        });
        Schema::create('ubicacion_stock', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('ubicacion_id')->index();
            $t->foreignId('product_id')->index();
            $t->foreignId('lote_id')->nullable();
            $t->decimal('cantidad', 14, 3)->default(0);
            $t->timestamps();
            $t->unique(['ubicacion_id', 'product_id', 'lote_id']);
        });
        Schema::create('ubicacion_movimientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('product_id');
            $t->foreignId('lote_id')->nullable();
            $t->foreignId('desde_id')->nullable(); // null = desde "sin ubicar"
            $t->foreignId('hacia_id')->nullable(); // null = sale (venta, baja) o vuelve a "sin ubicar"
            $t->decimal('cantidad', 14, 3);
            $t->string('motivo', 150)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicacion_movimientos');
        Schema::dropIfExists('ubicacion_stock');
        Schema::dropIfExists('ubicaciones');
    }
};
