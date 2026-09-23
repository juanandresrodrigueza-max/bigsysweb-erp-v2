<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tilde "informar a ARCA": apagado, la factura sale como comprobante interno (sin CAE, numeración propia, fuera de los libros de IVA).
return new class extends Migration
{
    public function up(): void { Schema::table('comprobantes', fn(Blueprint $t) => $t->boolean('sin_arca')->default(false)); }
    public function down(): void { Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn('sin_arca')); }
};
