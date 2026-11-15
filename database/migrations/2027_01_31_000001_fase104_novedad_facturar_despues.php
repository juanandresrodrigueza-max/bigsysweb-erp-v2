<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 104: aviso en la campanita: facturar después un interno y el aviso al cobrar con medio electrónico.
return new class extends Migration
{
    private const TITULO = 'Comprobante interno: «Facturar después» y aviso al cobrar con tarjeta o transferencia';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'comprobantes', 'url' => '/ayuda/facturar',
            'texto' => 'Desde un comprobante interno, el botón «Facturar (pasar a fiscal)» le pide el CAE a ARCA con la fecha de hoy y le da número fiscal; el stock, la cuenta corriente y los cobros quedan como estaban. Y cuando un interno se cobra con tarjeta, transferencia, Mercado Pago o billetera, el mostrador y el recibo avisan que conviene facturarlo: ARCA recibe esos movimientos. A Bigcito: «facturá el interno 12».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
