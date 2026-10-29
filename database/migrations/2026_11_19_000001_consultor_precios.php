<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 32.4–32.5: el Consultor de precios. Permisos por persona (ver costo y margen; ver a qué proveedores comprar) y las cotizaciones
// que salen del simulador por WhatsApp (PDF con link que dura 30 días).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 've_costos')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('ve_costos')->default(false);
                $t->boolean('ve_proveedores')->default(false);
            });
        }
        if (! Schema::hasTable('cotizaciones_consultor')) Schema::create('cotizaciones_consultor', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('contact_id')->nullable();
            $t->string('token', 40)->unique();
            $t->string('cliente', 150)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->unsignedTinyInteger('lista')->default(1);
            $t->json('items');
            $t->decimal('total', 14, 2)->default(0);
            $t->timestamp('expira_en')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones_consultor');
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['ve_costos', 've_proveedores']));
    }
};
