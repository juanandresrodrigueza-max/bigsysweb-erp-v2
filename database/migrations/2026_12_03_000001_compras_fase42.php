<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 42: una factura contra varias órdenes de compra, la mercadería que entra en otra empresa del grupo
// y el divisor aprendido por proveedor (factura por metro, el artículo es el caño de 4 m → se divide por 4).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->json('ordenes_compra')->nullable();
            $t->unsignedBigInteger('stock_en_business_id')->nullable();
        });
        Schema::table('proveedor_articulos', fn(Blueprint $t) => $t->decimal('divisor', 10, 4)->nullable());
    }

    public function down(): void
    {
        Schema::table('proveedor_articulos', fn(Blueprint $t) => $t->dropColumn('divisor'));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['ordenes_compra', 'stock_en_business_id']));
    }
};
