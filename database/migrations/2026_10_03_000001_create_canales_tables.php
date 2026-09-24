<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 11: tienda propia, portal del cliente, pedidos web / WhatsApp / marketplaces / delivery, reservas, menú QR y fidelización.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('tienda')->nullable();       // config de tienda y menú público
            $table->json('fidelizacion')->nullable(); // puntos por $, valor del punto, activo
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('en_tienda')->default(true);
            $table->string('descripcion_tienda', 500)->nullable();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('portal_token', 64)->nullable()->unique();
            $table->decimal('puntos', 12, 2)->default(0);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('nombre', 120)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->unsignedSmallInteger('personas')->default(2);
            $table->string('origen', 20)->default('manual'); // manual | web | whatsapp
            $table->string('token', 64)->nullable()->unique();
        });
        Schema::create('pedidos_web', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->unsignedInteger('numero');
            $table->string('canal', 20); // tienda | portal | whatsapp | mercadolibre | woocommerce | shopify | pedidosya | rappi | menu_qr
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('cliente'); // nombre, telefono, email, direccion, notas
            $table->json('items');   // [{product_id, descripcion, cantidad, precio_unit, total}]
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('envio', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('entrega', 15)->default('retiro'); // retiro | envio | mesa
            $table->string('pago', 20)->default('a_convenir'); // link | transferencia | efectivo | a_convenir | pagado_externo
            $table->string('estado', 15)->default('nuevo'); // nuevo | confirmado | preparando | enviado | entregado | cancelado
            $table->foreignId('comprobante_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('comanda_id')->nullable()->constrained('comandas')->nullOnDelete();
            $table->string('external_id', 80)->nullable();
            $table->string('token', 64)->unique();
            $table->text('texto_original')->nullable(); // mensaje de WhatsApp o payload resumido
            $table->text('notas')->nullable();
            $table->timestamp('confirmado_en')->nullable();
            $table->timestamp('entregado_en')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'estado', 'canal']);
        });
        Schema::create('canales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 20); // mercadolibre | woocommerce | shopify | pedidosya | rappi
            $table->string('nombre', 80);
            $table->json('credenciales')->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('sync_stock')->default(true);
            $table->boolean('sync_precios')->default(false);
            $table->boolean('importar_pedidos')->default(true);
            $table->string('token_entrada', 64)->unique(); // para el webhook de entrada
            $table->timestamp('ultimo_sync_en')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->unsignedInteger('pedidos_importados')->default(0);
            $table->timestamps();
        });
        Schema::create('puntos_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->decimal('puntos', 12, 2); // + suma, − canje
            $table->string('motivo', 150);
            $table->string('origen', 30)->nullable(); // comprobante | canje | ajuste | bienvenida
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos_movimientos');
        Schema::dropIfExists('canales');
        Schema::dropIfExists('pedidos_web');
        Schema::table('bookings', fn(Blueprint $t) => $t->dropColumn(['nombre', 'telefono', 'email', 'personas', 'origen', 'token']));
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn(['portal_token', 'puntos']));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['en_tienda', 'descripcion_tienda']));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['tienda', 'fidelizacion']));
    }
};
