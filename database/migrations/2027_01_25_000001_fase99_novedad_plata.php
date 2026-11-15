<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 99: aviso en la campanita: posición IVA, indicadores, reglas de conciliación y Mercado Pago por API.
return new class extends Migration
{
    private const TITULO = 'Contable: posición IVA, indicadores, reglas de conciliación y Mercado Pago solo';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'contable', 'url' => '/contable/iva/posicion',
            'texto' => 'Contable → Posición IVA: la cuenta del mes como la arma el contador (débito, crédito, percepciones y retenciones sufridas, saldo a favor arrastrado), cuánto se paga y cuándo vence; cerrás el mes y le copiás el texto al contador. Contable → Indicadores: doce meses de ventas, compras, gastos e IVA, margen bruto, días de cobro y de pago, liquidez y presión fiscal, con lo que falta cargar. En Conciliación: Reglas que actúan solas (marcá «Recordar» al registrar, ignorar o hacer recibo) y, en la cuenta de billetera, «Esta es la cuenta de Mercado Pago»: cobros, comisiones y devoluciones entran solos por API cada madrugada. A Bigcito: «¿cuánto IVA pago este mes?», «¿cómo vienen los indicadores?», «traé los movimientos de Mercado Pago», «¿qué quedó sin conciliar?».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
