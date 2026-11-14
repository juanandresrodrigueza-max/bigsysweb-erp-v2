<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 95: aviso en la campanita (sólo para quien tiene Big Restó): pedido desde la mesa, seguro y en vivo.
return new class extends Migration
{
    private const TITULO = 'Big Restó: pedido desde la mesa, seguro y en vivo';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'gastronomia', 'url' => '/gastronomia/reservas',
            'texto' => 'El QR de cada mesa ahora lleva un código secreto (reimprimí los que tenías desde Reservas y QR). Cuando el primer celular pide, la mesa queda abierta con un código de 4 números que ven el mozo y el cliente: si otro celular escanea el mismo QR, lo pone y pide a la misma cuenta. El cliente ve «lo que pidieron» con el estado de cada plato y el total, actualizado solo. En Carta y en Cocina hay «Se acabó»: el plato sale de la carta al instante y vuelve mañana. La cocina trabaja en tres pasos (Nuevo → En marcha → Listo) y el salón tiene semáforo de colores: qué espera cada mesa y hace cuánto. Los avisos de pedido, llamado y cuenta llegan al celular del mozo. A Bigcito: «¿qué mesa me llama?», «se acabó el pulpo».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
