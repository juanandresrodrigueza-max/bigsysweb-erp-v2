<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 27.1: lotes y vencimientos completos. Estado del lote (bloqueo / retiro), proveedor e ingreso, trazabilidad
// de cada entrada y salida por lote, lote elegido en la venta y configuración por empresa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $t) {
            $t->string('estado', 12)->default('disponible'); // disponible | bloqueado | retirado
            $t->string('motivo', 200)->nullable();
            $t->foreignId('proveedor_id')->nullable();
            $t->date('ingreso')->nullable();
        });
        Schema::create('lote_movimientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('lote_id')->index();
            $t->foreignId('product_id');
            $t->foreignId('stock_movement_id')->nullable();
            $t->decimal('cantidad', 14, 3); // + entra al lote, − sale
            $t->nullableMorphs('movable');
            $t->foreignId('contact_id')->nullable()->index();
            $t->string('motivo', 200)->nullable();
            $t->timestamps();
        });
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->foreignId('lote_id')->nullable());
        Schema::table('businesses', fn(Blueprint $t) => $t->json('lotes_config')->nullable());
    }

    public function down(): void
    {
        Schema::table('lotes', fn(Blueprint $t) => $t->dropColumn(['estado', 'motivo', 'proveedor_id', 'ingreso']));
        Schema::dropIfExists('lote_movimientos');
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->dropColumn('lote_id'));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('lotes_config'));
    }
};
