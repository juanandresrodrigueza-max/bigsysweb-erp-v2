<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 91: aviso en la campanita: reservas por WhatsApp con lista de espera y el delivery propio.
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', 'Reservas por WhatsApp y delivery propio')->exists()) return;
        DB::table('novedades')->insert(['titulo' => 'Reservas por WhatsApp y delivery propio', 'modulo' => 'gastronomia', 'url' => '/gastronomia/delivery',
            'texto' => 'Big Restó: si un cliente escribe al WhatsApp «quiero reservar para 4 el sábado a las 21», la reserva queda confirmada sola; si no hay lugar, queda en lista de espera y cuando alguien cancela pasa el primero y se le avisa. Por voz, a Bigcito: «reservá para cuatro el sábado a las nueve a nombre de Juan». Y en Big Restó → Delivery está el reparto propio: zonas de entrega con costo de envío (se suma solo a la comanda por la dirección, también en los pedidos por WhatsApp), el tablero Preparando → Listos → En camino → Entregados, «Salió» que le avisa al cliente con quién va y cuánto tarda, y «Entregado» que cobra con lo que trajo el repartidor y cierra la comanda.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', 'Reservas por WhatsApp y delivery propio')->delete(); }
};
