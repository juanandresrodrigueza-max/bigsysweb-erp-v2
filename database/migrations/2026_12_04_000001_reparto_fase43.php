<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 43: el chofer como usuario (ve sólo sus repartos) y el recorrido que hizo el camión, punto por punto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_entrega', fn(Blueprint $t) => $t->foreignId('chofer_user_id')->nullable()->constrained('users')->nullOnDelete());
        Schema::create('orden_entrega_posiciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_entrega_id')->constrained('ordenes_entrega')->cascadeOnDelete();
            $t->decimal('lat', 10, 7); $t->decimal('lng', 10, 7);
            $t->timestamp('en');
            $t->index(['orden_entrega_id', 'en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_entrega_posiciones');
        Schema::table('ordenes_entrega', fn(Blueprint $t) => $t->dropConstrainedForeignId('chofer_user_id'));
    }
};
