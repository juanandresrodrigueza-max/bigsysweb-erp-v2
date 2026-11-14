<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Fase 95 · Pedido desde la mesa, seguro y en vivo: el QR lleva un token por mesa (no el id), cada comanda de mesa nace con un
// código de 4 dígitos para que un segundo celular se sume a la misma cuenta, cocina marca "en marcha" plato por plato y la carta
// tiene "agotado hoy".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $t) { $t->string('token', 40)->nullable()->after('forma')->index(); });
        Schema::table('comandas', function (Blueprint $t) { $t->string('codigo', 6)->nullable()->after('numero'); });
        Schema::table('comanda_items', function (Blueprint $t) { $t->timestamp('empezado_en')->nullable()->after('enviado_en'); });
        Schema::table('products', function (Blueprint $t) { $t->date('agotado_hasta')->nullable()->after('va_cocina'); });
        // Las mesas que ya existen reciben su token: los QR impresos con ?mesa=id siguen abriendo (compatibilidad), pero conviene reimprimirlos.
        foreach (DB::table('mesas')->whereNull('token')->pluck('id') as $id) DB::table('mesas')->where('id', $id)->update(['token' => Str::random(24)]);
    }

    public function down(): void
    {
        Schema::table('mesas', fn(Blueprint $t) => $t->dropColumn('token'));
        Schema::table('comandas', fn(Blueprint $t) => $t->dropColumn('codigo'));
        Schema::table('comanda_items', fn(Blueprint $t) => $t->dropColumn('empezado_en'));
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('agotado_hasta'));
    }
};
