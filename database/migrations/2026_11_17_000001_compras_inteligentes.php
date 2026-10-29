<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 31: leer mejor la factura de compra y entender al proveedor. El bulto en la ficha, lo que se aprende de cada proveedor
// (sus códigos y descripciones → nuestro artículo), cómo factura cada CUIT (los conceptos raros del pie), facturas a medio cargar,
// remito sin precios (sólo stock) y la factura de ese remito (sólo contable).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->unsignedInteger('unidades_bulto')->nullable());   // cuántas unidades trae el bulto
        Schema::table('contacts', fn(Blueprint $t) => $t->json('compras_config')->nullable());               // factor_parentesis, tolerancia_oc, etc.
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->boolean('solo_stock')->default(false);      // remito del proveedor sin precios: entra al stock, no a contabilidad ni a la cuenta
            $t->boolean('solo_contable')->default(false);   // la factura de ese remito: contabilidad y cuenta, sin volver a mover stock
            $t->foreignId('remito_id')->nullable();
        });
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->boolean('actualiza_costo')->default(true)); // qué costo se va a escribir, renglón por renglón
        Schema::create('proveedor_articulos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->string('codigo', 60)->nullable();            // el código del proveedor
            $t->string('descripcion', 250)->nullable();      // como lo escribe el proveedor (normalizado)
            $t->foreignId('product_id');
            $t->unsignedInteger('unidades_bulto')->nullable();
            $t->decimal('ultimo_costo', 14, 4)->nullable();
            $t->date('ultima_fecha')->nullable();
            $t->timestamps();
            $t->index(['contact_id', 'descripcion']);
            $t->index(['contact_id', 'codigo']);
        });
        // Cómo factura cada proveedor, aprendido entre todos los negocios por CUIT: qué es cada concepto raro del pie.
        Schema::create('proveedor_reglas', function (Blueprint $t) {
            $t->id();
            $t->string('cuit', 13)->index();
            $t->string('concepto', 120);
            $t->string('tratamiento', 20);                   // percepcion_iibb | percepcion_iva | percepcion_ganancias | descuento | flete | ignorar
            $t->unsignedInteger('votos')->default(1);
            $t->timestamps();
            $t->unique(['cuit', 'concepto']);
        });
        Schema::create('compras_borradores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('contact_id')->nullable();
            $t->string('proveedor', 120)->nullable();
            $t->string('numero', 20)->nullable();
            $t->decimal('total', 14, 2)->nullable();
            $t->json('datos');                               // la lectura, lo que corregiste
            $t->json('adjuntos')->nullable();                // archivos guardados
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_borradores'); Schema::dropIfExists('proveedor_reglas'); Schema::dropIfExists('proveedor_articulos');
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->dropColumn('actualiza_costo'));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['solo_stock', 'solo_contable', 'remito_id']));
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn('compras_config'));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('unidades_bulto'));
    }
};
