<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 30.4–30.6: cada venta de tienda con sus avisos, los cargos de MercadoLibre, la factura subida y mandada al comprador,
// las devoluciones que vuelven el stock y los pedidos de datos personales (Tienda Nube).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_web', function (Blueprint $t) {
            $t->json('cargos')->nullable();                  // ML: pack_id, comprador_id, pagos (cargo por vender, envío, retenciones, liberación)
            $t->json('avisos')->nullable();                  // lo que hay que mirar antes de confirmar / por qué no entró sola
            $t->timestamp('factura_subida_at')->nullable();
            $t->timestamp('factura_enviada_at')->nullable();
            $t->string('devolucion', 12)->nullable();        // pendiente | resuelta
            $t->string('tracking', 80)->nullable();
        });
        Schema::create('pedidos_datos_personales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('canal_id')->nullable();
            $t->string('tipo', 10);                          // ver | borrar
            $t->json('cliente')->nullable();
            $t->json('pedidos')->nullable();                 // ids externos de los pedidos afectados
            $t->string('estado', 10)->default('pendiente');  // pendiente | hecho
            $t->timestamp('hecho_at')->nullable();
            $t->foreignId('hecho_por')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_datos_personales');
        Schema::table('pedidos_web', fn(Blueprint $t) => $t->dropColumn(['cargos', 'avisos', 'factura_subida_at', 'factura_enviada_at', 'devolucion', 'tracking']));
    }
};
