<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->unsignedTinyInteger('lista_precios')->default(1);
            $table->unsignedSmallInteger('dias_pago')->default(0);
            $table->decimal('descuento', 5, 2)->default(0);
            $table->decimal('limite_credito', 14, 2)->default(0);
            $table->string('color', 10)->nullable();
            $table->timestamps();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('tipo_cliente_id')->nullable()->after('type')->constrained('tipos_cliente')->nullOnDelete();
            $table->unsignedTinyInteger('lista_precios')->default(1)->after('credit_limit');
            $table->unsignedSmallInteger('dias_pago')->default(0)->after('lista_precios');
            $table->decimal('descuento', 5, 2)->default(0)->after('dias_pago');
            $table->boolean('percepcion_iibb')->default(false)->after('descuento');
            $table->string('crm_external_id', 60)->nullable()->after('notes');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('prices')->nullable()->after('price'); // listas 2..5
            $table->decimal('iva', 5, 2)->default(21)->after('cost');
        });

        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('modo', 12)->default('electronico'); // electronico | manual
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'numero']);
        });

        Schema::create('punto_venta_numeros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();
            $table->string('tipo', 4);
            $table->unsignedBigInteger('ultimo')->default(0);
            $table->unique(['punto_venta_id', 'tipo']);
        });

        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('punto_venta_id')->nullable()->constrained('puntos_venta')->nullOnDelete();
            $table->foreignId('origen_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->string('direccion', 6)->default('venta'); // venta | compra
            $table->string('tipo', 4); // FA FB FC FE NCA NCB NCC NDA NDB NDC REM PRE
            $table->unsignedSmallInteger('punto_venta')->nullable();
            $table->unsignedBigInteger('numero')->nullable();
            $table->date('fecha');
            $table->date('fecha_vto')->nullable();
            $table->string('condicion', 10)->default('cta_cte'); // contado | cta_cte
            $table->string('moneda', 3)->default('ARS');
            $table->decimal('cotizacion', 12, 4)->default(1);
            $table->decimal('neto', 14, 2)->default(0);
            $table->decimal('exento', 14, 2)->default(0);
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('percepciones', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('saldo', 14, 2)->default(0);
            $table->string('estado', 10)->default('borrador'); // borrador | emitido | anulado
            $table->string('afip_estado', 12)->nullable(); // aprobado | rechazado | simulado | no_aplica
            $table->string('cae', 20)->nullable();
            $table->date('cae_vto')->nullable();
            $table->json('afip_respuesta')->nullable();
            $table->boolean('es_acopio')->default(false);
            $table->boolean('stock_impactado')->default(false);
            $table->text('notas')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('emitido_en')->nullable();
            $table->timestamp('anulado_en')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'direccion', 'tipo', 'fecha']);
            $table->index(['business_id', 'contact_id']);
            $table->index(['business_id', 'estado']);
        });

        Schema::create('comprobante_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('descripcion');
            $table->decimal('cantidad', 14, 3)->default(1);
            $table->string('unidad', 10)->nullable();
            $table->decimal('precio_unit', 14, 4)->default(0);
            $table->decimal('descuento', 5, 2)->default(0); // porcentaje
            $table->decimal('alicuota_iva', 5, 2)->default(21);
            $table->decimal('neto', 14, 2)->default(0);
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('comprobante_impuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 30); // iva_21, iva_10_5, iva_27, iibb_cba, ...
            $table->decimal('base', 14, 2)->default(0);
            $table->decimal('alicuota', 6, 3)->default(0);
            $table->decimal('monto', 14, 2)->default(0);
        });

        Schema::create('comprobante_adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comprobante_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('tipo', 12); // imagen | audio | texto
            $table->string('archivo')->nullable();
            $table->text('transcripcion')->nullable();
            $table->json('items_detectados')->nullable();
            $table->decimal('confianza', 4, 2)->nullable();
            $table->boolean('revisado')->default(false);
            $table->timestamps();
        });

        Schema::create('cobros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('numero');
            $table->date('fecha');
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('a_cuenta', 14, 2)->default(0); // parte no imputada
            $table->string('estado', 10)->default('confirmado'); // confirmado | anulado
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'numero']);
        });

        Schema::create('cobro_medios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained()->cascadeOnDelete();
            $table->string('medio', 20); // efectivo | transferencia | cheque | mercadopago | billetera | tarjeta | retencion
            $table->decimal('monto', 14, 2);
            $table->string('referencia')->nullable();
            $table->json('datos')->nullable(); // cheque: banco, numero, fecha_pago, emisor
        });

        Schema::create('cobro_imputaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->decimal('monto', 14, 2);
        });

        Schema::create('cuenta_corriente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comprobante_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('cobro_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('fecha');
            $table->date('fecha_vto')->nullable();
            $table->string('tipo', 12); // factura | nc | nd | cobro | ajuste
            $table->string('concepto');
            $table->decimal('debe', 14, 2)->default(0);
            $table->decimal('haber', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['business_id', 'contact_id', 'fecha']);
        });

        Schema::create('acopios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->date('fecha');
            $table->date('fecha_limite')->nullable();
            $table->string('estado', 10)->default('abierto'); // abierto | parcial | cerrado | vencido
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('acopio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acopio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('descripcion');
            $table->decimal('cantidad_facturada', 14, 3);
            $table->decimal('cantidad_retirada', 14, 3)->default(0);
            $table->decimal('precio_congelado', 14, 4)->default(0);
        });

        Schema::create('acopio_retiros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acopio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('remito_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->date('fecha');
            $table->string('retirado_por')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('acopio_retiro_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acopio_retiro_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acopio_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('cantidad', 14, 3);
        });

        Schema::create('lotes_facturacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_location_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->date('fecha');
            $table->string('origen', 12); // presupuestos | remitos
            $table->unsignedInteger('cantidad')->default(0);
            $table->unsignedInteger('emitidos')->default(0);
            $table->unsignedInteger('con_error')->default(0);
            $table->string('estado', 12)->default('procesado');
            $table->json('detalle')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['lotes_facturacion', 'acopio_retiro_items', 'acopio_retiros', 'acopio_items', 'acopios', 'cuenta_corriente', 'cobro_imputaciones', 'cobro_medios', 'cobros', 'comprobante_adjuntos', 'comprobante_impuestos', 'comprobante_items', 'comprobantes', 'punto_venta_numeros', 'puntos_venta'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['prices', 'iva']));
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tipo_cliente_id');
            $t->dropColumn(['lista_precios', 'dias_pago', 'descuento', 'percepcion_iibb', 'crm_external_id']);
        });
        Schema::dropIfExists('tipos_cliente');
    }
};
