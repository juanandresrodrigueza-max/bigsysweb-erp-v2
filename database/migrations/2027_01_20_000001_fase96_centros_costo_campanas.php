<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 96 · Lo que faltaba de Contabilium: centros de costo con presupuesto anual contra real, campañas de mensajes a clientes
// (mail y WhatsApp) y entrar con Google (la configuración vive en sistema_config).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros_costo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->string('nombre', 60);
            $t->string('codigo', 20)->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['business_id', 'nombre']);
        });
        Schema::create('presupuestos_centro', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('centro_costo_id')->nullable()->constrained('centros_costo')->cascadeOnDelete(); // null = toda la empresa
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->decimal('monto', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['business_id', 'centro_costo_id', 'anio', 'mes'], 'presupuestos_centro_unico');
        });
        Schema::table('comprobantes', fn(Blueprint $t) => $t->foreignId('centro_costo_id')->nullable()->after('proyecto_id')->index());
        Schema::table('movimientos_fondos', fn(Blueprint $t) => $t->foreignId('centro_costo_id')->nullable()->after('expense_category_id')->index());

        Schema::create('campanas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable();
            $t->string('canal', 12); // mail | whatsapp
            $t->string('asunto', 150)->nullable();
            $t->text('texto');
            $t->json('filtro')->nullable();
            $t->unsignedInteger('destinatarios')->default(0);
            $t->unsignedInteger('enviados')->default(0);
            $t->unsignedInteger('pendientes')->default(0);
            $t->unsignedInteger('errores')->default(0);
            $t->timestamp('terminada_en')->nullable();
            $t->timestamps();
        });
        Schema::table('envios', fn(Blueprint $t) => $t->foreignId('campana_id')->nullable()->after('modelo_id')->index());
    }

    public function down(): void
    {
        Schema::table('envios', fn(Blueprint $t) => $t->dropColumn('campana_id'));
        Schema::dropIfExists('campanas');
        Schema::table('movimientos_fondos', fn(Blueprint $t) => $t->dropColumn('centro_costo_id'));
        Schema::table('comprobantes', fn(Blueprint $t) => $t->dropColumn('centro_costo_id'));
        Schema::dropIfExists('presupuestos_centro');
        Schema::dropIfExists('centros_costo');
    }
};
