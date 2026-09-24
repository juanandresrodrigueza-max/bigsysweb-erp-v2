<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 15 · Rentabilidad real: clasificación de costos y costo histórico por ítem vendido.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $t) {
            $t->string('tipo_costo', 10)->default('fijo')->after('color');       // fijo | variable
            $t->string('imputacion', 10)->default('indirecto')->after('tipo_costo'); // directo | indirecto
        });
        Schema::table('comprobante_items', function (Blueprint $t) {
            $t->decimal('costo_unit', 14, 4)->nullable()->after('precio_unit'); // costo del artículo al momento de emitir (en pesos)
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->json('rentabilidad')->nullable()->after('sueldos');
        });
        // Relleno histórico: los ítems ya emitidos toman el costo actual del artículo (lo mejor que hay).
        foreach (DB::table('comprobante_items')->join('products', 'products.id', '=', 'comprobante_items.product_id')->whereNull('comprobante_items.costo_unit')->select('comprobante_items.id', 'products.cost')->cursor() as $r) {
            DB::table('comprobante_items')->where('id', $r->id)->update(['costo_unit' => $r->cost]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_categories', fn(Blueprint $t) => $t->dropColumn(['tipo_costo', 'imputacion']));
        Schema::table('comprobante_items', fn(Blueprint $t) => $t->dropColumn('costo_unit'));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn('rentabilidad'));
    }
};
