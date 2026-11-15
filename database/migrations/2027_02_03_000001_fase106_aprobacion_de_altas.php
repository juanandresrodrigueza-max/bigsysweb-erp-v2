<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 106: altas con aprobación (como en el CRM). La empresa nueva entra al toque con acceso provisional por N horas
// (Panel → Sistema); si nadie la aprueba con la clave de aprobación, se bloquea hasta que alguien lo haga.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $t) {
            $t->string('aprobacion', 12)->default('aprobada')->after('alta_por'); // pendiente | aprobada | rechazada
            $t->unsignedBigInteger('aprobacion_pedida_por')->nullable()->after('aprobacion'); // superadmin que la dio de alta; null = se registró sola
            $t->timestamp('aprobada_en')->nullable()->after('aprobacion_pedida_por');
            $t->unsignedBigInteger('aprobada_por')->nullable()->after('aprobada_en');
            $t->json('aprobacion_avisos')->nullable()->after('aprobada_por'); // qué avisos ya salieron (por_vencer, bloqueada)
            $t->index('aprobacion');
        });
        Schema::table('users', fn(Blueprint $t) => $t->string('aprobacion', 12)->default('aprobada')->after('status'));
    }

    public function down(): void
    {
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['aprobacion', 'aprobacion_pedida_por', 'aprobada_en', 'aprobada_por', 'aprobacion_avisos']));
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('aprobacion'));
    }
};
