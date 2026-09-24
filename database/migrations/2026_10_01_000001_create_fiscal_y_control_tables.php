<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 9: FCE MiPyME, percepciones y retenciones con padrones, ejercicios contables e IPC, lotes y vencimientos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->boolean('fce')->default(false)->after('tipo');                 // Factura de Crédito Electrónica MiPyME
            $t->string('fce_estado', 12)->nullable()->after('fce');           // pendiente | aceptada | rechazada
            $t->date('fce_vto_pago')->nullable()->after('fce_estado');
        });
        Schema::table('comprobante_items', function (Blueprint $t) {
            $t->string('lote', 40)->nullable()->after('origen_item_id');
            $t->date('vencimiento')->nullable()->after('lote');
            $t->string('serie', 80)->nullable()->after('vencimiento');
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->string('cbu_fce', 22)->nullable();
            $t->json('impuestos')->nullable();                                 // percepción IIBB, retenciones, mínimos
            $t->unsignedTinyInteger('cierre_ejercicio_mes')->default(12);
        });
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('jurisdiccion_iibb', 10)->nullable()->after('percepcion_iibb');
            $t->decimal('alicuota_percepcion_iibb', 6, 3)->nullable()->after('jurisdiccion_iibb');
            $t->decimal('alicuota_retencion_iibb', 6, 3)->nullable()->after('alicuota_percepcion_iibb');
            $t->boolean('exento_iibb')->default(false)->after('alicuota_retencion_iibb');
            $t->boolean('retiene_ganancias')->default(true)->after('exento_iibb');
        });
        Schema::create('padrones_iibb', function (Blueprint $t) {
            $t->id();
            $t->string('jurisdiccion', 10)->index();      // ARBA | AGIP | CBA | SFE | MZA…
            $t->string('cuit', 11)->index();
            $t->decimal('alic_percepcion', 6, 3)->default(0);
            $t->decimal('alic_retencion', 6, 3)->default(0);
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();
            $t->string('fuente', 60)->nullable();
            $t->timestamps();
            $t->unique(['jurisdiccion', 'cuit']);
        });
        Schema::create('indices_ipc', function (Blueprint $t) {
            $t->id();
            $t->string('periodo', 7)->unique();           // YYYY-MM
            $t->decimal('valor', 14, 4);
            $t->string('fuente', 40)->nullable();
            $t->timestamps();
        });
        Schema::create('ejercicios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->date('desde');
            $t->date('hasta');
            $t->string('estado', 10)->default('abierto');  // abierto | cerrado
            $t->timestamp('cerrado_en')->nullable();
            $t->json('asientos')->nullable();               // {refundicion, cierre, apertura, ajuste}
            $t->timestamps();
        });
        Schema::table('cuentas_contables', fn(Blueprint $t) => $t->boolean('ajustable')->default(false)->after('imputable'));
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('perecedero')->default(false)->after('controla_stock');
            $t->boolean('seriado')->default(false)->after('perecedero');
        });
        Schema::create('lotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->index();
            $t->foreignId('product_id')->index();
            $t->foreignId('deposito_id')->nullable();
            $t->string('lote', 40)->nullable();
            $t->string('serie', 80)->nullable();
            $t->date('vencimiento')->nullable();
            $t->decimal('cantidad', 12, 3)->default(0);
            $t->decimal('costo_unit', 14, 4)->default(0);
            $t->timestamps();
            $t->index(['product_id', 'vencimiento']);
        });
        Schema::table('stock_movements', fn(Blueprint $t) => $t->foreignId('lote_id')->nullable()->after('deposito_id'));
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn(Blueprint $t) => $t->dropColumn('lote_id'));
        Schema::dropIfExists('lotes');
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['perecedero', 'seriado']));
        Schema::table('cuentas_contables', fn(Blueprint $t) => $t->dropColumn('ajustable'));
        Schema::dropIfExists('ejercicios');
        Schema::dropIfExists('indices_ipc');
        Schema::dropIfExists('padrones_iibb');
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn(['jurisdiccion_iibb', 'alicuota_percepcion_iibb', 'alicuota_retencion_iibb', 'exento_iibb', 'retiene_ganancias']));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['cbu_fce', 'impuestos', 'cierre_ejercicio_mes']));
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->dropColumn(['lote', 'vencimiento', 'serie']));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn(['fce', 'fce_estado', 'fce_vto_pago']));
    }
};
