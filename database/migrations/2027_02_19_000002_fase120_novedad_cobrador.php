<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 120.5: novedad en /conocer.
return new class extends Migration
{
    const TITULO = 'Bigcito cobrador: el cliente arma su plan por WhatsApp';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'clientes', 'url' => '/clientes/cobranzas',
            'texto' => 'Prendelo en Cobranzas y ponele tus límites (cuántas cuotas, interés, cuota mínima). Cuando un cliente con deuda vencida escribe por WhatsApp que no llega a pagar todo, Bigcito le arma un plan dentro de esos límites y, cuando acepta, te lo deja para aprobar. Lo que pida de más te lo pasa a vos.',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
