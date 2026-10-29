<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 30: tiendas conectadas con un botón, publicaciones del catálogo hacia cada tienda, lo ya publicado atado a los artículos
// y la configuración de cada canal (cuenta, colchón, lista, quién factura).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canales', function (Blueprint $t) {
            $t->json('config')->nullable();                       // contact_id, colchon, lista, factura_por, fecha_corte, automatico, recargo_pct, campos, claves_fiscales
            $t->string('external_user_id', 60)->nullable()->index(); // user_id de ML, store_id de Tienda Nube, dominio de Shopify
            $t->json('webhooks')->nullable();                     // ids de los avisos creados en la tienda
            $t->string('metodo', 12)->nullable();                 // oauth | claves | planilla
            $t->timestamp('conectado_en')->nullable();
            $t->timestamp('ultima_importacion_en')->nullable();
        });
        Schema::create('publicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('canal_id')->index();
            $t->foreignId('product_id')->nullable()->index();
            $t->string('external_id', 80);                        // id del ítem/producto en la tienda
            $t->string('variante_id', 80)->nullable();            // variante (Tienda Nube, Shopify, Woo variable)
            $t->string('titulo', 250)->nullable();
            $t->string('sku_remoto', 80)->nullable();
            $t->decimal('precio_remoto', 14, 2)->nullable();
            $t->integer('stock_remoto')->nullable();
            $t->unsignedTinyInteger('lista')->nullable();         // lista con la que se publicó (manda sobre rubro y tienda)
            $t->string('categoria_id', 40)->nullable();
            $t->string('origen', 12)->default('publicada');       // publicada (desde acá) | atada (ya estaba en la tienda)
            $t->boolean('adoptada')->default(false);              // el ERP le manda precio y stock
            $t->boolean('con_variantes')->default(false);
            $t->string('estado', 12)->default('activa');          // activa | pausada | error | cerrada
            $t->string('foto_url', 500)->nullable();
            $t->string('permalink', 500)->nullable();
            $t->json('datos')->nullable();
            $t->text('ultimo_error')->nullable();
            $t->timestamp('sync_at')->nullable();
            $t->timestamps();
            $t->unique(['canal_id', 'external_id', 'variante_id']);
        });
        Schema::create('rubros_canal', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('canal_id');
            $t->foreignId('rubro_id');
            $t->string('categoria_id', 40)->nullable();           // categoría de la tienda (ML: MLA1234; Woo/TN/Shopify: id de categoría o colección)
            $t->string('categoria_nombre', 150)->nullable();
            $t->unsignedTinyInteger('lista')->nullable();
            $t->json('atributos')->nullable();                    // marca, modelo, medidas, peso, iva, internos... lo que pide la categoría
            $t->timestamps();
            $t->unique(['canal_id', 'rubro_id']);
        });
        // Memoria de emparejamientos: título de la tienda → artículo. Sirve también a los pedidos por WhatsApp y a las compras.
        Schema::create('alias_articulos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->string('texto', 250);
            $t->foreignId('product_id');
            $t->string('origen', 20)->default('tienda');
            $t->timestamps();
            $t->unique(['business_id', 'texto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alias_articulos'); Schema::dropIfExists('rubros_canal'); Schema::dropIfExists('publicaciones');
        Schema::table('canales', fn(Blueprint $t) => $t->dropColumn(['config', 'external_user_id', 'webhooks', 'metodo', 'conectado_en', 'ultima_importacion_en']));
    }
};
