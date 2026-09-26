<?php

namespace App\Services\Migracion;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Copia de seguridad por empresa: un zip con cada tabla en JSON (+ CSV para leer en Excel) y los adjuntos. Se puede restaurar.
class BackupService
{
    // Tablas que pertenecen a la empresa (todas tienen business_id) en orden de dependencias.
    public const TABLAS = [
        'business_locations', 'roles', 'users', 'user_locations', 'tipos_cliente', 'rubros', 'depositos', 'puntos_venta', 'cuentas_fondos', 'expense_categories', 'contacts', 'vendedores', 'products', 'stock_depositos', 'recipes', 'recipe_items',
        'comprobantes', 'comprobante_items', 'comprobante_impuestos', 'comprobante_adjuntos', 'cuenta_corriente', 'lotes_facturacion', 'punto_venta_numeros', 'cobros', 'cobro_medios', 'cobro_imputaciones', 'pagos', 'pago_medios', 'pago_imputaciones', 'retenciones', 'cheques', 'cupones_tarjeta', 'liquidaciones_tarjeta',
        'movimientos_fondos', 'turnos_caja', 'stock_movements', 'lotes', 'transferencias_stock', 'transferencia_stock_items', 'inventarios', 'inventario_items', 'production_orders', 'ordenes_compra', 'orden_compra_items', 'ordenes_entrega', 'orden_entrega_items', 'abonos', 'envios', 'planes_pago', 'plan_pago_cuotas',
        'acopios', 'acopio_items', 'acopio_retiros', 'acopio_retiro_items', 'cuentas_contables', 'asientos', 'asiento_lineas', 'extractos_bancarios', 'extracto_items', 'ejercicios', 'mesas', 'comandas', 'comanda_items', 'alertas', 'audit_logs', 'webhooks', 'tickets', 'cotizaciones', 'importaciones_precios', 'importaciones',
        'despieces', 'despiece_cortes', 'despiece_operaciones', 'despiece_operacion_items', 'ubicaciones', 'ubicacion_stock', 'ubicacion_movimientos', 'lote_movimientos', 'precios_pactados', 'catalogos', 'descuentos_lista', 'contacto_personas', 'documentos', 'empleados', 'empleado_conceptos', 'sueldo_conceptos', 'liquidaciones', 'liquidacion_items'
    ];

    public function crear(Business $b, string $origen = 'manual', ?int $userId = null): Backup
    {
        $dir = "backups/{$b->id}"; Storage::disk('local')->makeDirectory($dir);
        $nombre = 'bigsys_' . $b->slug . '_' . now()->format('Ymd_His') . '.zip';
        $path = Storage::disk('local')->path("{$dir}/{$nombre}");
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $resumen = [];
        $zip->addFromString('manifest.json', json_encode(['app' => 'BigSysWeb', 'version' => 2, 'empresa' => $b->only('id', 'name', 'slug', 'cuit'), 'fecha' => now()->toDateTimeString(), 'tablas' => self::TABLAS], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('empresa.json', json_encode($b->toArray(), JSON_UNESCAPED_UNICODE));
        foreach (self::TABLAS as $t) {
            if (! Schema::hasTable($t)) continue;
            $filas = $this->filas($t, $b->id);
            if (! $filas) continue;
            $resumen[$t] = count($filas);
            $zip->addFromString("datos/{$t}.json", json_encode($filas, JSON_UNESCAPED_UNICODE));
            $zip->addFromString("csv/{$t}.csv", $this->csv($filas));
        }
        foreach (['adjuntos', "afip/{$b->id}", "logos", "documentos/{$b->id}"] as $carpeta) {
            foreach (Storage::disk('local')->allFiles($carpeta) as $f) {
                if ($carpeta === 'adjuntos' && ! str_contains($f, "/{$b->id}/")) continue;
                $zip->addFile(Storage::disk('local')->path($f), "archivos/{$f}");
            }
        }
        $zip->close();
        $bk = Backup::create(['business_id' => $b->id, 'archivo' => "{$dir}/{$nombre}", 'bytes' => filesize($path), 'origen' => $origen, 'resumen' => $resumen, 'user_id' => $userId]);
        // Se guardan los últimos 10 automáticos; los manuales quedan.
        foreach (Backup::withoutGlobalScopes()->where('business_id', $b->id)->where('origen', 'auto')->orderByDesc('id')->skip(10)->limit(100)->get() as $viejo) { Storage::disk('local')->delete($viejo->archivo); $viejo->delete(); }
        return $bk;
    }

    // Trae las filas de la empresa. Algunas tablas cuelgan de otra (ítems) y no tienen business_id directo.
    private function filas(string $t, int $bid): array
    {
        $padres = ['despiece_cortes' => ['despieces', 'despiece_id'], 'despiece_operacion_items' => ['despiece_operaciones', 'operacion_id'], 'empleado_conceptos' => ['empleados', 'empleado_id'], 'liquidacion_items' => ['liquidaciones', 'liquidacion_id'], 'comprobante_items' => ['comprobantes', 'comprobante_id'], 'comprobante_impuestos' => ['comprobantes', 'comprobante_id'], 'comprobante_adjuntos' => ['comprobantes', 'comprobante_id'], 'cobro_medios' => ['cobros', 'cobro_id'], 'cobro_imputaciones' => ['cobros', 'cobro_id'], 'pago_medios' => ['pagos', 'pago_id'], 'pago_imputaciones' => ['pagos', 'pago_id'],
            'recipe_items' => ['recipes', 'recipe_id'], 'transferencia_stock_items' => ['transferencias_stock', 'transferencia_id'], 'inventario_items' => ['inventarios', 'inventario_id'], 'orden_compra_items' => ['ordenes_compra', 'orden_compra_id'], 'orden_entrega_items' => ['ordenes_entrega', 'orden_entrega_id'], 'plan_pago_cuotas' => ['planes_pago', 'plan_pago_id'],
            'acopio_items' => ['acopios', 'acopio_id'], 'acopio_retiros' => ['acopios', 'acopio_id'], 'acopio_retiro_items' => ['acopio_retiros', 'acopio_retiro_id'], 'user_locations' => ['users', 'user_id'], 'punto_venta_numeros' => ['puntos_venta', 'punto_venta_id'], 'asiento_lineas' => ['asientos', 'asiento_id'], 'extracto_items' => ['extractos_bancarios', 'extracto_id'], 'comanda_items' => ['comandas', 'comanda_id']];
        if (Schema::hasColumn($t, 'business_id')) return DB::table($t)->where('business_id', $bid)->get()->map(fn($r) => (array) $r)->all();
        if (isset($padres[$t])) {
            [$pt, $fk] = $padres[$t];
            if (! Schema::hasColumn($t, $fk)) { $fk = collect(Schema::getColumnListing($t))->first(fn($c) => str_ends_with($c, '_id') && $c !== 'id' && str_starts_with($c, rtrim(preg_replace('/s$/', '', explode('_', $pt)[0]), 'e'))) ?? $fk; }
            if (! Schema::hasColumn($t, $fk)) return [];
            if (! Schema::hasColumn($pt, 'business_id')) return [];
            return DB::table($t)->whereIn($fk, DB::table($pt)->where('business_id', $bid)->select('id'))->get()->map(fn($r) => (array) $r)->all();
        }
        return [];
    }

    private function csv(array $filas): string
    {
        $cols = array_keys($filas[0]); $out = "\xEF\xBB\xBF" . implode(';', $cols) . "\n";
        foreach ($filas as $f) $out .= implode(';', array_map(fn($v) => is_null($v) ? '' : '"' . str_replace('"', '""', is_array($v) ? json_encode($v) : (string) $v) . '"', array_values($f))) . "\n";
        return $out;
    }

    // Restaura una copia sobre la empresa: primero guarda una copia de seguridad del estado actual, después borra y vuelve a cargar.
    public function restaurar(Business $b, string $zipPath, ?int $userId = null): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) throw ValidationException::withMessages(['archivo' => 'No se pudo abrir el archivo de backup.']);
        $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true);
        if (! $manifest || ($manifest['app'] ?? '') !== 'BigSysWeb') throw ValidationException::withMessages(['archivo' => 'El archivo no es un backup de BigSysWeb.']);
        if ((int) ($manifest['empresa']['id'] ?? 0) !== $b->id) throw ValidationException::withMessages(['archivo' => 'Este backup es de otra empresa (' . ($manifest['empresa']['name'] ?? '?') . ').']);
        $this->crear($b, 'pre_restauracion', $userId);
        $restauradas = [];
        Schema::disableForeignKeyConstraints(); // en SQLite tiene que ir fuera de la transacción
        DB::transaction(function () use ($zip, $b, $manifest, &$restauradas) {
            $tablas = array_values(array_filter($manifest['tablas'] ?? self::TABLAS, fn($t) => Schema::hasTable($t)));
            foreach (array_reverse($tablas) as $t) {
                if ($t === 'users') { DB::table($t)->where('business_id', $b->id)->where('is_superadmin', false)->delete(); continue; }
                foreach (array_chunk(array_column($this->filas($t, $b->id), 'id'), 500) as $ids) DB::table($t)->whereIn('id', $ids)->delete();
            }
            foreach ($tablas as $t) {
                $json = $zip->getFromName("datos/{$t}.json"); if ($json === false) continue;
                $filas = json_decode($json, true) ?: [];
                $cols = Schema::getColumnListing($t);
                foreach (array_chunk($filas, 200) as $chunk) {
                    $chunk = array_map(fn($f) => array_intersect_key(array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $f), array_flip($cols)), $chunk);
                    DB::table($t)->upsert($chunk, ['id']);
                }
                $restauradas[$t] = count($filas);
            }
            foreach (array_filter(array_map(fn($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn($n) => str_starts_with($n, 'archivos/') && ! str_ends_with($n, '/')) as $n) {
                Storage::disk('local')->put(substr($n, 9), $zip->getFromName($n));
            }
            $emp = json_decode($zip->getFromName('empresa.json') ?: '{}', true) ?: [];
            $b->fill(array_intersect_key($emp, array_flip(['name', 'email', 'phone', 'address', 'cuit', 'razon_social', 'condicion_iva', 'recordatorios', 'whatsapp_settings', 'cbu_fce', 'impuestos', 'cierre_ejercicio_mes'])))->save();
        });
        Schema::enableForeignKeyConstraints();
        $zip->close();
        AuditLog::registrar('editar', $b, 'Restauró un backup: ' . count($restauradas) . ' tablas');
        return $restauradas;
    }

    // Export "para llevarse todo": el mismo zip, pero el usuario lo usa fuera del sistema (CSV por tabla).
    public function exportarTodo(Business $b, ?int $userId = null): Backup { return $this->crear($b, 'manual', $userId); }
}
