<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 118: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Conectá tu WhatsApp escaneando un QR';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'configuracion', 'url' => '/configuracion/tienda#canales',
            'texto' => 'En Configuración → Tienda y canales → Pedidos por WhatsApp, tocá «Conectar WhatsApp»: se abre Meta, escaneás el QR desde tu app WhatsApp Business y listo, sin copiar claves. El número sigue andando en tu celular y el ERP toma los pedidos, responde consultas con Bigcito y atiende al equipo.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
