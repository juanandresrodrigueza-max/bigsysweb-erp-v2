<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 25.7: cobrador (como mov.codcob del BigSys). La comisión por cobranza va a quien cobró, no a quien vendió.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros', function (Blueprint $t) { $t->foreignId('cobrador_id')->nullable()->index(); });
        Schema::table('contacts', function (Blueprint $t) { $t->foreignId('cobrador_id')->nullable(); });
    }

    public function down(): void
    {
        Schema::table('cobros', fn(Blueprint $t) => $t->dropColumn('cobrador_id'));
        Schema::table('contacts', fn(Blueprint $t) => $t->dropColumn('cobrador_id'));
    }
};
