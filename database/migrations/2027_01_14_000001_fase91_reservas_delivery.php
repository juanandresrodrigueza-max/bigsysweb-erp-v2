<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 91: delivery propio: zona y costo de envío, quién lo lleva, cuándo salió y cuándo se entregó.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comandas', function (Blueprint $t) {
            if (! Schema::hasColumn('comandas', 'zona')) $t->string('zona', 60)->nullable()->after('direccion');
            if (! Schema::hasColumn('comandas', 'costo_envio')) $t->decimal('costo_envio', 12, 2)->default(0)->after('zona');
            if (! Schema::hasColumn('comandas', 'repartidor')) $t->string('repartidor', 80)->nullable()->after('costo_envio');
            if (! Schema::hasColumn('comandas', 'salio_en')) $t->timestamp('salio_en')->nullable()->after('repartidor');
            if (! Schema::hasColumn('comandas', 'entregado_en')) $t->timestamp('entregado_en')->nullable()->after('salio_en');
        });
        // Las reservas suman el estado "waitlist" (lista de espera): el enum pasa a texto libre.
        if (DB::getDriverName() === 'pgsql') DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');
        Schema::table('bookings', fn(Blueprint $t) => $t->string('status', 20)->default('pending')->change());
    }

    public function down(): void
    {
        Schema::table('comandas', fn(Blueprint $t) => $t->dropColumn(['zona', 'costo_envio', 'repartidor', 'salio_en', 'entregado_en']));
    }
};
