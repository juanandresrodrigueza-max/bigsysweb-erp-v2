<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 103: aviso en la campanita: el comprobante interno por cliente y en el mostrador.
return new class extends Migration
{
    private const TITULO = 'Comprobante interno: por cliente, en el mostrador y fuera de lo del contador';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'comprobantes', 'url' => '/ayuda/facturar',
            'texto' => 'El comprobante interno (numeración propia, sin ARCA) ahora se puede dejar por defecto en la ficha de cada cliente y tildar en el mostrador al cobrar. Mueve stock, cuenta corriente y caja como cualquier venta; no va a ARCA, a los libros de IVA ni a la contabilidad, y no aparece en el panel ni en las exportaciones del contador. En Comprobantes hay filtro «Sólo internos / Sólo fiscales». A Bigcito: «hacele un comprobante interno a Pérez por…», «¿cuánto vendí este mes, fiscal e interno?».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
