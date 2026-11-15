<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 107: el link de WhatsApp lleva el mensaje codificado y con un recordatorio largo pasaba los 500 caracteres; en PostgreSQL el
// envío quedaba en error. Pasa a texto.
return new class extends Migration
{
    public function up(): void { Schema::table('envios', fn(Blueprint $t) => $t->text('link')->nullable()->change()); }
    public function down(): void { Schema::table('envios', fn(Blueprint $t) => $t->string('link', 500)->nullable()->change()); }
};
