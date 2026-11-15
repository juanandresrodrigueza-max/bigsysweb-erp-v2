<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 101: aviso en la campanita: la migración completa desde BIG Connect y el control de cierre.
return new class extends Migration
{
    private const TITULO = 'Migrar desde BigSys: ahora trae todo y controla el cierre';

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades') || DB::table('novedades')->where('titulo', self::TITULO)->exists()) return;
        DB::table('novedades')->insert(['titulo' => self::TITULO, 'modulo' => 'configuracion', 'url' => '/configuracion/importar',
            'texto' => 'Configuración → Importar → Traer todo desde BIG Connect trae ahora también rubros y departamentos, vendedores con sus comisiones, empleados como legajos, cajas y bancos con su saldo, cheques en cartera, precios por proveedor y las ventas de los últimos 12 meses (cada paso con su tilde). El «Control de cierre» compara los totales de BigSys con los del ERP: cantidades, saldos de clientes y proveedores, inventario, cheques y el saldo de cada caja. Cuando todo cierra, se apaga el viejo. Desde el primer día, la ficha del artículo muestra lo vendido en BigSys mes a mes y «Qué reponer» sugiere cantidades por lo vendido. A Bigcito: «migrá todo desde BIG Connect», «¿cierra la migración?».',
            'publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { if (Schema::hasTable('novedades')) DB::table('novedades')->where('titulo', self::TITULO)->delete(); }
};
