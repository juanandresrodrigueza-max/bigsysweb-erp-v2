<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 7: cadena de precios, dólar, órdenes de compra, vendedores con comisiones, recibos con descuento/interés, rendición de turno.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('precio_compra', 14, 2)->default(0)->after('cost');      // precio de lista del proveedor (neto)
            $t->decimal('descuento_proveedor', 5, 2)->default(0)->after('precio_compra');
            $t->json('margenes')->nullable()->after('prices');                    // {"1": 40, "2": 35, ...} % sobre el costo
            $t->string('moneda', 3)->default('ARS')->after('margenes');           // ARS | USD (precios expresados en dólares)
            $t->decimal('desc_cant_min', 12, 3)->default(0)->after('moneda');     // a partir de esta cantidad…
            $t->decimal('desc_cant_pct', 5, 2)->default(0)->after('desc_cant_min'); // …se aplica este % de descuento
            $t->string('imagen')->nullable()->after('description');
        });

        Schema::create('cotizaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->index();  // null = cotización general del sistema
            $t->date('fecha')->index();
            $t->string('tipo', 12)->default('oficial');          // oficial | blue | mep | tarjeta
            $t->decimal('compra', 12, 2)->default(0);
            $t->decimal('venta', 12, 2)->default(0);
            $t->string('fuente', 40)->nullable();                 // dolarapi | manual
            $t->timestamps();
            $t->unique(['business_id', 'fecha', 'tipo']);
        });

        Schema::create('vendedores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('nombre', 80);
            $t->string('email', 120)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->decimal('comision_venta', 5, 2)->default(0);   // % sobre lo facturado (neto)
            $t->decimal('comision_cobro', 5, 2)->default(0);   // % sobre lo cobrado
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::table('comprobantes', fn(Blueprint $t) => $t->foreignId('vendedor_id')->nullable()->after('user_id')->index());
        Schema::table('cobros', function (Blueprint $t) {
            $t->foreignId('vendedor_id')->nullable()->after('user_id');
            $t->decimal('descuento', 14, 2)->default(0)->after('total');  // bonificación que cancela deuda sin cobrarse
            $t->decimal('interes', 14, 2)->default(0)->after('descuento'); // interés cobrado además de la deuda
        });
        Schema::table('pagos', function (Blueprint $t) {
            $t->decimal('descuento', 14, 2)->default(0)->after('total');
            $t->decimal('interes', 14, 2)->default(0)->after('descuento');
        });
        Schema::table('contacts', function (Blueprint $t) {
            $t->foreignId('vendedor_id')->nullable()->after('tipo_cliente_id');
            $t->decimal('interes_mora', 5, 2)->default(0)->after('dias_pago'); // % mensual sobre saldos vencidos
        });

        Schema::create('ordenes_compra', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('business_location_id')->nullable();
            $t->foreignId('contact_id');
            $t->foreignId('user_id')->nullable();
            $t->unsignedInteger('numero');
            $t->date('fecha');
            $t->date('fecha_entrega')->nullable();
            $t->string('estado', 12)->default('borrador'); // borrador | enviada | parcial | recibida | cancelada
            $t->decimal('total', 14, 2)->default(0);
            $t->text('notas')->nullable();
            $t->string('origen', 20)->nullable();           // manual | sugerido | faltantes
            $t->timestamp('enviada_en')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'numero']);
        });
        Schema::create('orden_compra_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_compra_id')->index();
            $t->foreignId('product_id')->nullable();
            $t->string('descripcion', 150);
            $t->decimal('cantidad', 12, 3);
            $t->decimal('precio_unit', 14, 4)->default(0);
            $t->decimal('recibido', 12, 3)->default(0);
            $t->string('notas', 150)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
        });
        Schema::table('comprobantes', fn(Blueprint $t) => $t->foreignId('orden_compra_id')->nullable()->after('origen_id'));

        Schema::table('turnos_caja', function (Blueprint $t) {
            $t->json('esperado_medios')->nullable()->after('diferencia'); // {efectivo: 1000, tarjeta: 500, ...} según el sistema
            $t->json('rendicion')->nullable()->after('esperado_medios');  // lo declarado por el cajero por medio
        });

        Schema::create('importaciones_precios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('contact_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('archivo', 150);
            $t->json('mapeo')->nullable();
            $t->unsignedInteger('leidos')->default(0);
            $t->unsignedInteger('creados')->default(0);
            $t->unsignedInteger('actualizados')->default(0);
            $t->unsignedInteger('errores')->default(0);
            $t->json('detalle')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones_precios');
        Schema::table('turnos_caja', fn(Blueprint $t) => $t->dropColumn(['esperado_medios', 'rendicion']));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['orden_compra_id', 'vendedor_id']));
        Schema::dropIfExists('orden_compra_items');
        Schema::dropIfExists('ordenes_compra');
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn(['vendedor_id', 'interes_mora']));
        Schema::table('pagos', fn(Blueprint $t) => $t->dropColumn(['descuento', 'interes']));
        Schema::table('cobros', fn(Blueprint $t) => $t->dropColumn(['vendedor_id', 'descuento', 'interes']));
        Schema::dropIfExists('vendedores');
        Schema::dropIfExists('cotizaciones');
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['precio_compra', 'descuento_proveedor', 'margenes', 'moneda', 'desc_cant_min', 'desc_cant_pct', 'imagen']));
    }
};
