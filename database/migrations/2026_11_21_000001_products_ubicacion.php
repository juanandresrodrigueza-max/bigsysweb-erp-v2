<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 34.5: ubicación general del artículo (góndola, estante) para cambiarla en masa; la de cada depósito sigue en stock_depositos.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'ubicacion')) Schema::table('products', fn(Blueprint $t) => $t->string('ubicacion', 60)->nullable()->after('marca'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'ubicacion')) Schema::table('products', fn(Blueprint $t) => $t->dropColumn('ubicacion'));
    }
};
