<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Segunda escala de descuento por cantidad en artículos (ej. 10% desde 10 unidades, 15% desde 50).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('desc_cant2_min', 12, 3)->default(0)->after('desc_cant_pct');
            $t->decimal('desc_cant2_pct', 5, 2)->default(0)->after('desc_cant2_min');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['desc_cant2_min', 'desc_cant2_pct']));
    }
};
