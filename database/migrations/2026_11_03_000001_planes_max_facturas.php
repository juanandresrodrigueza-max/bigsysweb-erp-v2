<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Límite de facturas por mes según el plan (-1 = sin límite).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', fn(Blueprint $t) => $t->integer('max_facturas_mes')->default(-1));
        foreach (['free' => 30, 'starter' => 300, 'pro' => 3000, 'enterprise' => -1] as $slug => $n) DB::table('plans')->where('slug', $slug)->update(['max_facturas_mes' => $n]);
    }

    public function down(): void { Schema::table('plans', fn(Blueprint $t) => $t->dropColumn('max_facturas_mes')); }
};
