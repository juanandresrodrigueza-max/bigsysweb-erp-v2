<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Integración con el CRM de BigSys: credenciales cifradas por empresa (url, secreto del handoff, clave de API, secreto del webhook).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->text('crm_settings')->nullable()->after('arba_settings'));
    }

    public function down(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('crm_settings'));
    }
};
