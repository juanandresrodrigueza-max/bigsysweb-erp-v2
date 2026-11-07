<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 44.6: el catálogo puede mostrar el stock de esta empresa, de todas las del grupo o de una en particular.
return new class extends Migration
{
    public function up(): void { Schema::table('catalogos', fn(Blueprint $t) => $t->string('stock_de', 20)->nullable()); }
    public function down(): void { Schema::table('catalogos', fn(Blueprint $t) => $t->dropColumn('stock_de')); }
};
