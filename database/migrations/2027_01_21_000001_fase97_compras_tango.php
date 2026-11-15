<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 97 · Lo de Tango en compras y stock: autorización de órdenes de compra por monto, despachos de importación con costo
// nacionalizado (el stock comprometido se calcula, no se guarda).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $t) {
            $t->string('autorizacion', 20)->nullable()->after('estado'); // null | pendiente | autorizada | rechazada
            $t->foreignId('autorizada_por')->nullable()->after('autorizacion');
            $t->timestamp('autorizada_en')->nullable()->after('autorizada_por');
            $t->string('autorizacion_nota', 300)->nullable()->after('autorizada_en');
        });
        Schema::create('despachos_importacion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('business_location_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('contact_id')->nullable(); // proveedor del exterior
            $t->foreignId('comprobante_id')->nullable(); // la factura del exterior cargada como compra, si está
            $t->foreignId('deposito_id')->nullable();
            $t->unsignedInteger('numero');
            $t->string('despacho', 40)->nullable(); // número de despacho de aduana
            $t->date('fecha');
            $t->string('moneda', 3)->default('USD');
            $t->decimal('cotizacion', 12, 4)->default(1);
            $t->string('estado', 20)->default('borrador'); // borrador | nacionalizado | anulado
            $t->decimal('fob_total', 14, 2)->default(0);      // en pesos
            $t->decimal('gastos_total', 14, 2)->default(0);   // en pesos, sólo los que van al costo
            $t->decimal('credito_fiscal', 14, 2)->default(0); // IVA y percepciones del despacho (no van al costo)
            $t->decimal('costo_total', 14, 2)->default(0);
            $t->text('notas')->nullable();
            $t->timestamp('nacionalizado_en')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'numero']);
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('despacho_id')->constrained('despachos_importacion')->cascadeOnDelete();
            $t->foreignId('product_id')->nullable();
            $t->string('descripcion', 200);
            $t->decimal('cantidad', 14, 3);
            $t->decimal('fob_unit', 14, 4);            // en la moneda del despacho
            $t->decimal('fob_total_ars', 14, 2)->default(0);
            $t->decimal('gastos_ars', 14, 2)->default(0);
            $t->decimal('costo_unit_ars', 14, 4)->default(0);
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });
        Schema::create('despacho_gastos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('despacho_id')->constrained('despachos_importacion')->cascadeOnDelete();
            $t->string('tipo', 30); // flete, seguro, derechos, tasa_estadistica, despachante, deposito_fiscal, otros, iva, percepcion_iva, percepcion_ganancias
            $t->string('concepto', 120)->nullable();
            $t->decimal('monto', 14, 2);
            $t->boolean('al_costo')->default(true); // IVA y percepciones: false (son crédito fiscal)
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despacho_gastos');
        Schema::dropIfExists('despacho_items');
        Schema::dropIfExists('despachos_importacion');
        Schema::table('ordenes_compra', fn(Blueprint $t) => $t->dropColumn(['autorizacion', 'autorizada_por', 'autorizada_en', 'autorizacion_nota']));
    }
};
