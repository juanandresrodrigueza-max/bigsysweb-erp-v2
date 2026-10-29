<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A cuántos metros del cliente quedó cada visita, medido en el momento (antes de que el sistema aprenda la ubicación con esa misma visita).
return new class extends Migration
{
    public function up(): void { Schema::table('visitas', fn(Blueprint $t) => $t->unsignedInteger('distancia_m')->nullable()); }
    public function down(): void { Schema::table('visitas', fn(Blueprint $t) => $t->dropColumn('distancia_m')); }
};
