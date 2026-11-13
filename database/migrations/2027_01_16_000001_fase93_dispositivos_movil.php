<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 93: los celulares con la app nativa (iPhone y Android): a cada uno le llegan los avisos por el canal nativo (FCM / APNs).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_movil', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $t->string('plataforma', 10);            // ios | android
            $t->string('token', 300);
            $t->string('hash', 64)->unique();         // sha256 del token
            $t->string('modelo', 120)->nullable();
            $t->string('version_app', 30)->nullable();
            $t->timestamp('ultimo_uso_en')->nullable();
            $t->timestamp('ultimo_envio_en')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'plataforma']);
        });
    }

    public function down(): void { Schema::dropIfExists('dispositivos_movil'); }
};
