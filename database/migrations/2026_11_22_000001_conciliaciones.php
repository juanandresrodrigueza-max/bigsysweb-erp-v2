<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 35: qué es cada fondo (si se concilia, qué procesadora liquida ahí, lo pactado) y los lotes de conciliación.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cuentas_fondos', 'concilia')) Schema::table('cuentas_fondos', function (Blueprint $t) {
            $t->boolean('concilia')->default(true)->after('es_default');
            $t->json('conciliacion_config')->nullable()->after('concilia'); // procesadoras [], comision_pactada, plazo_pactado, banco_id, terminales {}
        });
        if (! Schema::hasColumn('businesses', 'conciliacion')) Schema::table('businesses', fn(Blueprint $t) => $t->json('conciliacion')->nullable()); // formatos aprendidos por procesadora, códigos de concepto del banco
        if (! Schema::hasTable('conciliaciones')) Schema::create('conciliaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('tipo', 10);                 // tarjeta | banco | caja
            $t->string('origen', 20)->nullable();   // fiserv | naranjax | gocuotas | mercadopago | nave | otro | galicia…
            $t->foreignId('cuenta_fondos_id')->nullable()->constrained('cuentas_fondos')->nullOnDelete();
            $t->foreignId('banco_id')->nullable()->constrained('cuentas_fondos')->nullOnDelete();
            $t->string('archivo')->nullable();
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();
            $t->json('resumen')->nullable();
            $t->json('resultado')->nullable();
            $t->json('control')->nullable();
            $t->string('estado', 12)->default('propuesta'); // propuesta | asentada | deshecha
            $t->json('asentado')->nullable();
            $t->timestamp('probado_en')->nullable();
            $t->timestamp('asentado_en')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'tipo', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliaciones');
        if (Schema::hasColumn('businesses', 'conciliacion')) Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('conciliacion'));
        if (Schema::hasColumn('cuentas_fondos', 'concilia')) Schema::table('cuentas_fondos', fn(Blueprint $t) => $t->dropColumn(['concilia', 'conciliacion_config']));
    }
};
