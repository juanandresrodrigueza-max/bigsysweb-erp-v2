<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 120.3: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Casilla de facturas: las compras llegan leídas';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'proveedores', 'url' => '/proveedores/compras',
            'texto' => 'En Comprar → Facturas de compra ahora hay una casilla: subí hasta 20 facturas juntas (PDF o fotos) o reenviá las que te llegan por mail a la dirección de tu empresa, y aparecen ya leídas para que sólo las revises y registres. Además, en la ficha de cada cliente podés ver su situación en el Banco Central antes de fiarle.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
