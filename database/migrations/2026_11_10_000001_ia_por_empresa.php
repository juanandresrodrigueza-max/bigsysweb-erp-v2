<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cada empresa usa su propia clave de IA (Anthropic) y paga su consumo. Se guarda cifrada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->text('ia_settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('ia_settings'));
    }
};
