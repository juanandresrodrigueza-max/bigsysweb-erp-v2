<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 116: pedidos de tiendas que llegan por el CRM (idempotentes por crm_order_id) y cobros anotados en el CRM (crm_payment_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_web', function (Blueprint $t) { $t->string('crm_order_id', 60)->nullable()->after('external_id'); $t->index(['business_id', 'crm_order_id']); });
        Schema::table('comprobantes', function (Blueprint $t) { $t->string('crm_order_id', 60)->nullable()->after('crm_quote_id'); $t->index(['business_id', 'crm_order_id']); });
        // Referencia del CRM para lo que entra en la carga inicial (comprobantes históricos «crm-doc-{id}», saldos iniciales «crm-saldo-inicial-{id}»).
        if (! Schema::hasColumn('comprobantes', 'crm_external_id')) Schema::table('comprobantes', function (Blueprint $t) { $t->string('crm_external_id', 80)->nullable()->after('crm_order_id'); $t->index(['business_id', 'crm_external_id']); });
        Schema::table('cobros', function (Blueprint $t) { $t->string('crm_payment_id', 80)->nullable()->after('modo'); $t->index(['business_id', 'crm_payment_id']); });
    }

    public function down(): void
    {
        Schema::table('pedidos_web', function (Blueprint $t) { $t->dropIndex(['business_id', 'crm_order_id']); $t->dropColumn('crm_order_id'); });
        Schema::table('comprobantes', function (Blueprint $t) { $t->dropIndex(['business_id', 'crm_order_id']); $t->dropColumn('crm_order_id'); });
        if (Schema::hasColumn('comprobantes', 'crm_external_id')) Schema::table('comprobantes', function (Blueprint $t) { $t->dropIndex(['business_id', 'crm_external_id']); $t->dropColumn('crm_external_id'); });
        Schema::table('cobros', function (Blueprint $t) { $t->dropIndex(['business_id', 'crm_payment_id']); $t->dropColumn('crm_payment_id'); });
    }
};
