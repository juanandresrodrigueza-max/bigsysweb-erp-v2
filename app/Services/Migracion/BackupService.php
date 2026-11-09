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
            $restauradas = $this->cargarTablas($zip, $b, $manifest);
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

    // Borra lo de la empresa y vuelve a cargar cada tabla desde el zip (lo comparten la restauración real y el ensayo).
    private function cargarTablas(\ZipArchive $zip, Business $b, array $manifest): array
    {
        $restauradas = [];
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
        return $restauradas;
    }

    // ── Fase 78: la copia tiene que servir ──────────────────────────────────────────────────────────

    // Abre el zip y comprueba: manifiesto de esta empresa, cada tabla del resumen presente y legible, conteos iguales al resumen, y que la
    // base no tenga hoy MENOS filas que la copia en más de un 10 % (una tabla que se vació se nota acá). Guarda el veredicto en la copia.
    public function verificar(Backup $bk): array
    {
        $problemas = []; $tablas = 0;
        $path = Storage::disk('local')->path($bk->archivo);
        if (! is_file($path)) $problemas[] = 'El archivo de la copia no está en el disco.';
        else {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) $problemas[] = 'El zip no se puede abrir (¿se cortó al escribirlo?).';
            else {
                $m = json_decode($zip->getFromName('manifest.json') ?: '', true);
                if (! $m || ($m['app'] ?? '') !== 'BigSysWeb') $problemas[] = 'Sin manifiesto de BigSysWeb.';
                elseif ((int) ($m['empresa']['id'] ?? 0) !== (int) $bk->business_id) $problemas[] = 'El manifiesto es de otra empresa.';
                foreach ((array) ($bk->resumen ?? []) as $t => $n) {
                    $json = $zip->getFromName("datos/{$t}.json");
                    if ($json === false) { $problemas[] = "Falta la tabla {$t}."; continue; }
                    $filas = json_decode($json, true);
                    if (! is_array($filas)) { $problemas[] = "La tabla {$t} no se puede leer."; continue; }
                    if (count($filas) !== (int) $n) { $problemas[] = "La tabla {$t} tiene " . count($filas) . " filas y el resumen dice {$n}."; continue; }
                    $tablas++;
                    if (Schema::hasTable($t) && $bk->business_id) { $hoy = count($this->filas($t, $bk->business_id)); if ($n >= 20 && $hoy < $n * 0.9) $problemas[] = "Hoy {$t} tiene {$hoy} filas y la copia {$n}: ¿se borró algo?"; }
                }
                $zip->close();
            }
        }
        $r = ['ok' => ! $problemas, 'tablas' => $tablas, 'problemas' => $problemas];
        $bk->forceFill(['verificado_en' => now(), 'verificacion' => $r])->save();
        return $r;
    }

    // Ensayo de restauración: carga la copia sobre la empresa dentro de una transacción que se deshace. Prueba que la copia se restaura
    // de verdad (claves, columnas, JSON) sin tocar los datos reales. Guarda el resultado en la copia.
    public function ensayarRestauracion(Backup $bk): array
    {
        $b = Business::withoutGlobalScopes()->withTrashed()->find($bk->business_id);
        $path = Storage::disk('local')->path($bk->archivo); $zip = new \ZipArchive(); $r = ['ok' => false, 'tablas' => 0, 'detalle' => null];
        if (! $b || $zip->open($path) !== true) { $r['detalle'] = 'No se pudo abrir la copia.'; $bk->forceFill(['ensayo_en' => now(), 'ensayo_ok' => false])->save(); return $r; }
        $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true) ?: [];
        Schema::disableForeignKeyConstraints();
        DB::beginTransaction();
        try {
            $cargadas = $this->cargarTablas($zip, $b, $manifest);
            $mal = [];
            foreach ($cargadas as $t => $n) { $hay = count($this->filas($t, $b->id)); if ($hay !== $n) $mal[] = "{$t}: cargó {$hay} de {$n}"; }
            $r = ['ok' => ! $mal, 'tablas' => count($cargadas), 'detalle' => $mal ? implode('; ', array_slice($mal, 0, 5)) : 'Se restauraron ' . count($cargadas) . ' tablas y se deshizo todo.'];
        } catch (\Throwable $e) { $r = ['ok' => false, 'tablas' => 0, 'detalle' => mb_substr($e->getMessage(), 0, 300)]; }
        finally { DB::rollBack(); Schema::enableForeignKeyConstraints(); $zip->close(); }
        $bk->forceFill(['ensayo_en' => now(), 'ensayo_ok' => $r['ok']])->save();
        return $r;
    }

    // Copia fuera del servidor: al disco configurado en BACKUP_DISK (p. ej. un bucket S3 compatible). Devuelve "disco:ruta" o null si no hay disco.
    public function subirRemoto(Backup $bk): ?string
    {
        $disk = config('services.backups.disk'); if (! $disk || ! config("filesystems.disks.{$disk}")) return null;
        $b = Business::withoutGlobalScopes()->withTrashed()->find($bk->business_id);
        $destino = 'bigsysweb/' . ($b?->slug ?? $bk->business_id) . '/' . basename($bk->archivo);
        $fh = fopen(Storage::disk('local')->path($bk->archivo), 'r');
        try { if (! Storage::disk($disk)->put($destino, $fh)) throw new \RuntimeException("El disco {$disk} no aceptó el archivo."); } finally { if (is_resource($fh)) fclose($fh); }
        $bk->forceFill(['remoto' => "{$disk}:{$destino}", 'remoto_en' => now()])->save();
        return $bk->remoto;
    }

    public static function remotoConfigurado(): bool { $d = config('services.backups.disk'); return (bool) ($d && config("filesystems.disks.{$d}")); }

    // Para el panel y la salud: por cada empresa activa con copia automática, su última copia y cómo está.
    public static function estadoPorEmpresa(): array
    {
        $out = [];
        foreach (Business::where('is_active', true)->orderBy('name')->get() as $b) {
            $bk = Backup::withoutGlobalScopes()->where('business_id', $b->id)->latest('id')->first();
            $ensayo = Backup::withoutGlobalScopes()->where('business_id', $b->id)->whereNotNull('ensayo_en')->latest('ensayo_en')->first();
            $out[] = ['business_id' => $b->id, 'empresa' => $b->name, 'auto' => (bool) $b->backup_auto, 'ultima' => $bk?->created_at?->toIso8601String(), 'hace_horas' => $bk ? (int) abs(now()->diffInHours($bk->created_at)) : null,
                'mb' => $bk ? round($bk->bytes / 1048576, 2) : null, 'verificada' => $bk?->verificado_en ? (bool) ($bk->verificacion['ok'] ?? false) : null, 'problemas' => $bk?->verificacion['problemas'] ?? [],
                'ensayo' => $ensayo ? ['ok' => (bool) $ensayo->ensayo_ok, 'cuando' => $ensayo->ensayo_en->toIso8601String(), 'hace_dias' => (int) abs(now()->diffInDays($ensayo->ensayo_en))] : null, 'remoto' => (bool) $bk?->remoto];
        }
        return $out;
    }

    // Export "para llevarse todo": el mismo zip, pero el usuario lo usa fuera del sistema (CSV por tabla).
    public function exportarTodo(Business $b, ?int $userId = null): Backup { return $this->crear($b, 'manual', $userId); }
}
