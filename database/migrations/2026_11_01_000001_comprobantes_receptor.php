<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Datos del receptor cargados en la factura sin cliente guardado (consumidor final identificado).
return new class extends Migration
{
    public function up(): void { Schema::table('comprobantes', fn(Blueprint $t) => $t->json('receptor')->nullable()); }
    public function down(): void { Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn('receptor')); }
};
