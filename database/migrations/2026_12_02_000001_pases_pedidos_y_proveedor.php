<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 42: el pase tiene tipos. «pase» entre depósitos (como hasta ahora), «pedido» de una sucursal al depósito (todavía no
// mueve stock: del pedido se arma el pase) y «proveedor» (cambio o devolución: sale a la cuenta de cambios y se marca cuando vuelve).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferencias_stock', function (Blueprint $t) {
            $t->string('tipo', 12)->default('pase');
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $t->foreignId('pedido_id')->nullable()->constrained('transferencias_stock')->nullOnDelete();
        });
        Schema::table('transferencia_stock_items', fn(Blueprint $t) => $t->decimal('cantidad_pedida', 14, 3)->nullable());
    }

    public function down(): void
    {
        Schema::table('transferencia_stock_items', fn(Blueprint $t) => $t->dropColumn('cantidad_pedida'));
        Schema::table('transferencias_stock', function (Blueprint $t) { $t->dropConstrainedForeignId('pedido_id'); $t->dropConstrainedForeignId('contact_id'); $t->dropColumn('tipo'); });
    }
};
