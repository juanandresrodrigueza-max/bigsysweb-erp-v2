<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 16 · Base de datos y escala: columnas de estado con margen (PostgreSQL/MySQL sí respetan el largo) e índices para las consultas más pesadas.
return new class extends Migration
{
    private const ANCHAS = [
        'cuenta_corriente' => ['tipo'], 'comprobante_adjuntos' => ['tipo'], 'puntos_venta' => ['modo'], 'comprobantes' => ['condicion'], 'abonos' => ['condicion', 'frecuencia'],
        'plan_cuentas' => ['tipo'], 'cotizaciones' => ['tipo'], 'mesas' => ['tipo'], 'cheques' => ['estado'], 'entregas' => ['estado'], 'recordatorios_cobranza' => ['estado', 'canal'],
        'cuotas_financiacion' => ['estado'], 'financiaciones' => ['estado'], 'lotes_facturacion' => ['origen'], 'movimientos_fondos' => ['origen'], 'padron_iibb' => ['jurisdiccion'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::ANCHAS as $tabla => $cols) {
                if (! Schema::hasTable($tabla)) continue;
                Schema::table($tabla, function (Blueprint $t) use ($tabla, $cols) {
                    foreach ($cols as $c) if (Schema::hasColumn($tabla, $c)) $t->string($c, 30)->change();
                });
            }
        }
        // El código de artículo es único por empresa, no en todo el sistema (dos empresas pueden tener el SKU "001").
        if (in_array('products_sku_unique', $this->indicesDe('products'), true)) {
            Schema::table('products', fn(Blueprint $t) => $t->dropUnique('products_sku_unique'));
            Schema::table('products', fn(Blueprint $t) => $t->unique(['business_id', 'sku'], 'products_business_sku_unique'));
        }
        if (in_array('products_business_id_sku_idx', $this->indicesDe('products'), true)) Schema::table('products', fn(Blueprint $t) => $t->dropIndex('products_business_id_sku_idx'));
        $indices = [
            'comprobantes' => [['business_id', 'contact_id', 'fecha'], ['business_id', 'estado', 'fecha'], ['business_id', 'vendedor_id'], ['business_id', 'proyecto_id'], ['business_id', 'emitido_en'], ['business_id', 'direccion', 'saldo']],
            'comprobante_items' => [['comprobante_id'], ['product_id']],
            'cuenta_corriente' => [['contact_id', 'fecha'], ['business_id', 'fecha_vto']],
            'movimientos_fondos' => [['cuenta_fondos_id', 'fecha'], ['business_id', 'origen', 'fecha'], ['business_id', 'expense_category_id']],
            'products' => [['business_id', 'name'], ['business_id', 'barcode'], ['business_id', 'rubro_id'], ['business_id', 'supplier_id']],
            'contacts' => [['business_id', 'name'], ['business_id', 'cuit'], ['business_id', 'type']],
            'stock_movimientos' => [['product_id', 'fecha'], ['business_id', 'fecha']],
            'asientos' => [['business_id', 'fecha'], ['business_id', 'origen', 'origen_id']],
            'asiento_lineas' => [['cuenta_id']],
            'cobros' => [['business_id', 'fecha'], ['contact_id']],
            'pagos' => [['business_id', 'fecha'], ['contact_id']],
            'audit_logs' => [['business_id', 'created_at']],
            'alertas' => [['business_id', 'leida_en']],
        ];
        foreach ($indices as $tabla => $lista) {
            if (! Schema::hasTable($tabla)) continue;
            foreach ($lista as $cols) {
                $faltan = array_filter($cols, fn($c) => ! Schema::hasColumn($tabla, $c));
                if ($faltan) continue;
                $nombre = $tabla . '_' . implode('_', $cols) . '_idx';
                if (strlen($nombre) > 60) $nombre = substr($tabla, 0, 20) . '_' . substr(md5(implode('_', $cols)), 0, 10) . '_idx';
                if (in_array($nombre, $this->indicesDe($tabla), true)) continue;
                Schema::table($tabla, fn(Blueprint $t) => $t->index($cols, $nombre));
            }
        }
        $this->indexarClavesForaneas();
    }

    // Toda columna *_id (clave foránea) sin índice recibe uno: MySQL los crea solo, PostgreSQL y SQLite no, y sin ellos cada join escanea la tabla entera.
    private function indexarClavesForaneas(): void
    {
        foreach (Schema::getTableListing() as $tabla) {
            $tabla = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
            if (in_array($tabla, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'], true)) continue;
            $indices = Schema::getIndexes($tabla);
            $primeras = array_map(fn($i) => $i['columns'][0] ?? null, $indices); // una columna ya cubierta como primera de un índice no necesita otro
            foreach (Schema::getColumns($tabla) as $col) {
                $c = $col['name'];
                if (! str_ends_with($c, '_id') || in_array($c, $primeras, true)) continue;
                $nombre = substr($tabla, 0, 40) . '_' . substr($c, 0, 20) . '_fk_idx';
                if (in_array($nombre, array_column($indices, 'name'), true)) continue;
                Schema::table($tabla, fn(Blueprint $t) => $t->index([$c], $nombre));
            }
        }
    }

    private function indicesDe(string $tabla): array
    {
        try { return array_column(Schema::getIndexes($tabla), 'name'); } catch (\Throwable) { return []; }
    }

    public function down(): void {}
};
