<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Credenciales del web service de ARBA (COT) por empresa, cifradas.
return new class extends Migration
{
    public function up(): void { Schema::table('businesses', fn(Blueprint $t) => $t->text('arba_settings')->nullable()); }
    public function down(): void { Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('arba_settings')); }
};
