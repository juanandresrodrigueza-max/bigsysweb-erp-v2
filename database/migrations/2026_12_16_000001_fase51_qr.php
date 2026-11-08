<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 51: desde el QR de la mesa se llama al mozo o se pide la cuenta.
return new class extends Migration
{
    public function up(): void { Schema::table('comandas', function (Blueprint $t) { $t->string('llamado', 20)->nullable(); $t->timestamp('llamado_en')->nullable(); }); }
    public function down(): void { Schema::table('comandas', fn(Blueprint $t) => $t->dropColumn(['llamado', 'llamado_en'])); }
};
