<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 12: POS sin conexión, balanza e impresora, avisos al dueño y agenda de turnos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('pos')->nullable();    // balanza, impresora, impresión automática
            $table->json('avisos')->nullable(); // resumen diario y alertas al dueño por WhatsApp
        });
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->string('offline_id', 64)->nullable()->unique(); // ventas hechas sin conexión (idempotencia al sincronizar)
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('comprobante_id')->nullable()->constrained()->nullOnDelete();
            $table->string('color', 10)->nullable();
            $table->timestamp('recordado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn(Blueprint $t) => $t->dropColumn(['comprobante_id', 'color', 'recordado_en']));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn('offline_id'));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['pos', 'avisos']));
    }
};
