<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 8: cupones y liquidación de tarjeta, entregas parciales y hojas de reparto, abonos recurrentes, envíos, links de pago, cobranzas y planes de pago.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupones_tarjeta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('cobro_id')->nullable();
            $t->foreignId('cobro_medio_id')->nullable();
            $t->foreignId('contact_id')->nullable();
            $t->string('tarjeta', 30)->nullable();        // Visa, Mastercard, Cabal, Naranja…
            $t->string('numero', 40)->nullable();         // n° de cupón / autorización
            $t->string('lote', 20)->nullable();
            $t->unsignedTinyInteger('cuotas')->default(1);
            $t->decimal('monto', 14, 2);
            $t->date('fecha');
            $t->string('estado', 12)->default('cartera'); // cartera | liquidado | rechazado
            $t->foreignId('liquidacion_tarjeta_id')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('liquidaciones_tarjeta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('business_location_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('numero', 40)->nullable();          // n° de liquidación del emisor
            $t->date('fecha');
            $t->string('tarjeta', 30)->nullable();
            $t->foreignId('cuenta_fondos_id');              // banco donde acredita
            $t->decimal('bruto', 14, 2)->default(0);
            $t->decimal('comision', 14, 2)->default(0);
            $t->decimal('iva_comision', 14, 2)->default(0);
            $t->decimal('ret_iva', 14, 2)->default(0);
            $t->decimal('ret_iibb', 14, 2)->default(0);
            $t->decimal('ret_ganancias', 14, 2)->default(0);
            $t->decimal('otros', 14, 2)->default(0);
            $t->decimal('neto', 14, 2)->default(0);
            $t->string('estado', 12)->default('registrada');
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::table('comprobante_items', function (Blueprint $t) {
            $t->decimal('cantidad_entregada', 12, 3)->default(0)->after('total');
            $t->decimal('cantidad_facturada', 12, 3)->default(0)->after('cantidad_entregada');
            $t->foreignId('origen_item_id')->nullable()->after('cantidad_facturada');
        });
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->boolean('entrega_pendiente')->default(false)->after('es_acopio'); // factura que no mueve stock hasta el remito
            $t->string('public_token', 40)->nullable()->unique()->after('pdf_path');
            $t->timestamp('aprobado_en')->nullable()->after('public_token');
            $t->timestamp('rechazado_en')->nullable()->after('aprobado_en');
            $t->text('respuesta_cliente')->nullable()->after('rechazado_en');
            $t->string('link_pago', 500)->nullable()->after('respuesta_cliente');
            $t->string('link_pago_id', 80)->nullable()->after('link_pago');
            $t->foreignId('abono_id')->nullable()->after('orden_compra_id');
        });

        Schema::create('ordenes_entrega', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('business_location_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->unsignedInteger('numero');
            $t->date('fecha');
            $t->string('repartidor', 80)->nullable();
            $t->string('vehiculo', 40)->nullable();
            $t->string('estado', 12)->default('pendiente'); // pendiente | en_curso | entregada | cancelada
            $t->text('notas')->nullable();
            $t->timestamp('entregada_en')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'numero']);
        });
        Schema::create('orden_entrega_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_entrega_id')->index();
            $t->foreignId('comprobante_id');
            $t->string('direccion', 200)->nullable();
            $t->string('estado', 14)->default('pendiente'); // pendiente | entregado | no_entregado
            $t->string('observacion', 200)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamp('entregado_en')->nullable();
        });

        Schema::create('abonos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id');
            $t->string('descripcion', 120);
            $t->json('items');                                // [{product_id?, descripcion, cantidad, precio_unit, alicuota_iva}]
            $t->string('condicion', 10)->default('cta_cte');
            $t->string('frecuencia', 12)->default('mensual'); // mensual | bimestral | trimestral | semestral | anual
            $t->unsignedTinyInteger('dia_emision')->default(1);
            $t->date('desde');
            $t->date('hasta')->nullable();
            $t->json('meses_excluidos')->nullable();
            $t->boolean('emitir_auto')->default(true);
            $t->boolean('activo')->default(true);
            $t->unsignedInteger('cuota_actual')->default(0);
            $t->date('proximo')->nullable();
            $t->timestamp('ultimo_emitido_en')->nullable();
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::create('envios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('contact_id')->nullable()->index();
            $t->string('modelo', 40);
            $t->unsignedBigInteger('modelo_id');
            $t->string('canal', 12);                          // mail | whatsapp
            $t->string('tipo', 30)->nullable();               // comprobante | recibo | ficha | oc | recordatorio | pago
            $t->string('destino', 150)->nullable();
            $t->string('asunto', 200)->nullable();
            $t->text('cuerpo')->nullable();
            $t->string('estado', 12)->default('enviado');     // enviado | pendiente | error
            $t->string('link', 500)->nullable();
            $t->text('error')->nullable();
            $t->timestamp('enviado_en')->nullable();
            $t->timestamps();
            $t->index(['modelo', 'modelo_id']);
        });

        Schema::table('businesses', function (Blueprint $t) {
            $t->json('recordatorios')->nullable();       // {activo, dias: [-3,0,7,30], canales: ['mail','whatsapp'], texto}
            $t->json('whatsapp_settings')->nullable();   // {token, phone_id, desde}
        });

        Schema::create('planes_pago', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->unsignedInteger('numero');
            $t->date('fecha');
            $t->decimal('deuda', 14, 2);
            $t->decimal('interes_pct', 6, 2)->default(0);
            $t->decimal('interes', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->unsignedTinyInteger('cuotas_n');
            $t->foreignId('nd_comprobante_id')->nullable();
            $t->string('estado', 12)->default('vigente');  // vigente | pagado | cancelado
            $t->text('notas')->nullable();
            $t->timestamps();
        });
        Schema::create('plan_pago_cuotas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_pago_id')->index();
            $t->unsignedTinyInteger('numero');
            $t->date('vencimiento');
            $t->decimal('monto', 14, 2);
            $t->decimal('pagado', 14, 2)->default(0);
            $t->string('estado', 12)->default('pendiente'); // pendiente | pagada | vencida
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_pago_cuotas');
        Schema::dropIfExists('planes_pago');
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['recordatorios', 'whatsapp_settings']));
        Schema::dropIfExists('envios');
        Schema::dropIfExists('abonos');
        Schema::dropIfExists('orden_entrega_items');
        Schema::dropIfExists('ordenes_entrega');
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['entrega_pendiente', 'public_token', 'aprobado_en', 'rechazado_en', 'respuesta_cliente', 'link_pago', 'link_pago_id', 'abono_id']));
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->dropColumn(['cantidad_entregada', 'cantidad_facturada', 'origen_item_id']));
        Schema::dropIfExists('liquidaciones_tarjeta');
        Schema::dropIfExists('cupones_tarjeta');
    }
};
