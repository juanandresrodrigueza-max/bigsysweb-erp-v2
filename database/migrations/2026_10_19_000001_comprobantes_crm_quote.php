<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Presupuestos que nacen en el CRM: se guarda el id del presupuesto del CRM para no duplicar y para saltar de uno a otro.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->unsignedBigInteger('crm_quote_id')->nullable()->after('offline_id');
            $t->index(['business_id', 'crm_quote_id']);
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) { $t->dropIndex(['business_id', 'crm_quote_id']); $t->dropColumn('crm_quote_id'); });
    }
};
