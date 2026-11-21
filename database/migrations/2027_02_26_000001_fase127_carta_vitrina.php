<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 127: la carta vitrina de Big Restó: foto de cada plato subida desde el sistema, ingredientes y alérgenos.
return new class extends Migration
{
    const TITULO = 'Carta vitrina: tu carta del QR, plato por plato y con fotos grandes';

    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->text('ingredientes')->nullable();
            $t->json('alergenos')->nullable();
            $t->string('foto_carta')->nullable();          // la foto subida desde Big Restó → Carta (disco local)
            $t->boolean('foto_gira')->default(false);       // la foto es el plato visto desde arriba: en la vitrina gira
        });

        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'gastronomia', 'url' => '/gastronomia/carta',
            'texto' => 'En Big Restó → Carta, el botón «Carta vitrina» cambia cómo se ve la carta del QR: arriba la historia del local y el horario, y después un plato por pantalla, con la foto grande, los ingredientes, los alérgenos, los tamaños y el precio. Se pasa de un plato a otro con el dedo. Las fotos se suben desde el celular en las opciones de cada plato. Si la foto es el plato visto desde arriba (una pizza, por ejemplo), en la carta gira despacio. El pedido desde la mesa sigue igual.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete();
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn(['ingredientes', 'alergenos', 'foto_carta', 'foto_gira']));
    }
};
