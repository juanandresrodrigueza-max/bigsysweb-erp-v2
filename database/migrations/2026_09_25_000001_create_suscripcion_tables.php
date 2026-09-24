<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 3: ciclo de vida de la suscripción (prueba, activa, gracia, suspendida), cobros de BigSys y datos del panel superadmin.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('grace_ends_at')->nullable()->after('trial_ends_at');
            $table->boolean('auto_renew')->default(false)->after('payment_method');
            $table->string('mp_preapproval_id')->nullable()->after('external_id');
            $table->text('notas')->nullable()->after('mp_preapproval_id');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('vertical', 30)->nullable()->after('condicion_iva');   // corralon, gastronomia, retail, minimarket, otro
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->string('suspension_motivo')->nullable()->after('suspended_at');
            $table->text('notas_internas')->nullable()->after('suspension_motivo');
            $table->unsignedBigInteger('alta_por')->nullable()->after('notas_internas');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 40)->nullable()->after('avatar');
        });

        // Cobros de suscripción (lo que las empresas le pagan a BigSys).
        Schema::create('pagos_suscripcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->date('fecha');
            $table->string('ciclo', 10)->default('monthly');                    // monthly | yearly
            $table->date('periodo_desde')->nullable();
            $table->date('periodo_hasta')->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('medio', 20)->default('mercadopago');                // mercadopago | transferencia | efectivo | cortesia
            $table->string('estado', 20)->default('pendiente');                 // pendiente | aprobado | rechazado | anulado
            $table->string('external_id')->nullable();                          // id de pago MP
            $table->string('preference_id')->nullable();
            $table->string('referencia')->nullable();
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();                  // quién lo registró (dueño o superadmin)
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'estado']);
        });

        // Parámetros globales del sistema (los maneja el superadmin).
        Schema::create('sistema_config', function (Blueprint $table) {
            $table->string('clave', 60)->primary();
            $table->json('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistema_config');
        Schema::dropIfExists('pagos_suscripcion');
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('mobile'));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['vertical', 'suspended_at', 'suspension_motivo', 'notas_internas', 'alta_por']));
        Schema::table('subscriptions', fn(Blueprint $t) => $t->dropColumn(['grace_ends_at', 'auto_renew', 'mp_preapproval_id', 'notas']));
    }
};
