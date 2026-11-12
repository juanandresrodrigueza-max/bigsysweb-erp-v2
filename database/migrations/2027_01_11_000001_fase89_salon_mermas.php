<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 89: plano del salón (posición y forma de cada mesa), pedido por asiento y cortesías en la comanda, arqueo ciego y mermas con motivo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $t) {
            $t->decimal('pos_x', 5, 2)->nullable()->after('orden');   // % del ancho del sector en el plano
            $t->decimal('pos_y', 5, 2)->nullable()->after('pos_x');   // % del alto
            $t->string('forma', 12)->default('cuadrada')->after('pos_y'); // cuadrada | redonda | larga
        });
        Schema::table('comanda_items', function (Blueprint $t) {
            $t->unsignedTinyInteger('asiento')->nullable()->after('ronda');
            $t->boolean('cortesia')->default(false)->after('asiento');
            $t->string('cortesia_motivo', 60)->nullable()->after('cortesia');
        });
        Schema::table('businesses', function (Blueprint $t) { $t->json('caja_config')->nullable(); });
        Schema::create('mermas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deposito_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('comanda_item_id')->nullable()->constrained('comanda_items')->nullOnDelete();
            $t->date('fecha');
            $t->decimal('cantidad', 12, 3);
            $t->decimal('costo_unit', 14, 2)->default(0);
            $t->string('motivo', 30);
            $t->string('notas', 200)->nullable();
            $t->timestamps();
            $t->index(['business_id', 'fecha']);
            $t->index(['business_id', 'motivo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mermas');
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('caja_config'));
        Schema::table('comanda_items', fn(Blueprint $t) => $t->dropColumn(['asiento', 'cortesia', 'cortesia_motivo']));
        Schema::table('mesas', fn(Blueprint $t) => $t->dropColumn(['pos_x', 'pos_y', 'forma']));
    }
};
