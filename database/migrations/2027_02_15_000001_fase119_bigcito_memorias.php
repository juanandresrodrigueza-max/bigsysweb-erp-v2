<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 119.5: lo que Bigcito recuerda (apodos, preferencias y datos que el usuario le pidió que no se olvide).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bigcito_memorias')) return;
        Schema::create('bigcito_memorias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete(); // null = vale para toda la empresa
            $t->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->string('texto', 300);
            $t->timestamps();
            $t->index(['business_id', 'user_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('bigcito_memorias'); }
};
