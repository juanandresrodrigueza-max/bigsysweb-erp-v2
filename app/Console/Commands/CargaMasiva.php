<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\PuntoVenta;
use App\Models\Rubro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Prueba de escala: llena una empresa con muchos artículos, clientes y comprobantes (inserciones directas, en bloques) para medir tiempos.
// Uso: php artisan erp:carga-masiva --empresa=1 --articulos=50000 --clientes=5000 --comprobantes=200000
class CargaMasiva extends Command
{
    protected $signature = 'erp:carga-masiva {--empresa=} {--articulos=50000} {--clientes=5000} {--comprobantes=200000} {--meses=24}';
    protected $description = 'Carga masiva de datos sintéticos para probar rendimiento (NO usar en producción)';

    public function handle(): int
    {
        if (app()->isProduction()) { $this->error('No se corre en producción.'); return 1; }
        $b = $this->option('empresa') ? Business::find($this->option('empresa')) : Business::first();
        if (! $b) { $this->error('No hay empresa.'); return 1; }
        $suc = BusinessLocation::withoutGlobalScopes()->where('business_id', $b->id)->orderByDesc('is_default')->first();
        $pv = PuntoVenta::withoutGlobalScopes()->where('business_id', $b->id)->first();
        $rubroIds = Rubro::withoutGlobalScopes()->where('business_id', $b->id)->pluck('id')->all() ?: [null];
        $uid = $b->owner_id;
        $nArt = (int) $this->option('articulos'); $nCli = (int) $this->option('clientes'); $nComp = (int) $this->option('comprobantes'); $meses = max(1, (int) $this->option('meses'));
        $t0 = microtime(true);
        DB::disableQueryLog();

        // ---- Artículos ----
        $base = (int) DB::table('products')->max('id');
        $palabras = ['Cemento', 'Hierro', 'Ladrillo', 'Arena', 'Cal', 'Malla', 'Chapa', 'Caño', 'Pintura', 'Tornillo', 'Clavo', 'Yeso', 'Membrana', 'Cable', 'Llave', 'Codo', 'Adhesivo', 'Cerámica', 'Piedra', 'Alambre'];
        $now = now()->toDateTimeString();
        $this->withProgressBar(range(0, (int) ceil($nArt / 1000) - 1), function ($k) use ($b, $suc, $rubroIds, $palabras, $now, $base, $nArt) {
            $rows = [];
            for ($i = $k * 1000; $i < min($nArt, ($k + 1) * 1000); $i++) {
                $n = $base + $i + 1; $cost = mt_rand(100, 500000) / 10;
                $rows[] = ['business_id' => $b->id, 'business_location_id' => $suc?->id, 'rubro_id' => $rubroIds[$i % count($rubroIds)], 'name' => $palabras[$i % 20] . ' ' . ($i % 97) . ' mm x ' . ($i % 13) . ' m · lote ' . $n, 'sku' => 'CM' . str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'barcode' => '779' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),
                    'tipo' => 'producto', 'unit' => 'un', 'cost' => $cost, 'price' => round($cost * 1.45, 2), 'iva' => 21, 'stock' => mt_rand(0, 500), 'stock_min' => 5, 'active' => true, 'controla_stock' => true, 'moneda' => 'ARS', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('products')->insert($rows);
        });
        $this->newLine(); $this->info("Artículos: {$nArt} en " . round(microtime(true) - $t0, 1) . ' s');

        // ---- Clientes ----
        $t1 = microtime(true); $baseC = (int) DB::table('contacts')->max('id');
        foreach (array_chunk(range(1, $nCli), 1000) as $chunk) {
            DB::table('contacts')->insert(array_map(fn($i) => ['business_id' => $b->id, 'type' => 'customer', 'name' => 'Cliente ' . ($baseC + $i) . ' ' . ['SRL', 'SA', 'e Hijos', 'Construcciones', 'Obras'][$i % 5], 'cuit' => '30-' . str_pad((string) (10000000 + $baseC + $i), 8, '0', STR_PAD_LEFT) . '-' . ($i % 10), 'condicion_iva' => $i % 3 ? 'Responsable Inscripto' : 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1 + $i % 6, 'dias_pago' => [0, 30, 60][$i % 3], 'descuento' => 0, 'credit_limit' => 0, 'balance' => 0, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
        $this->info("Clientes: {$nCli} en " . round(microtime(true) - $t1, 1) . ' s');

        // ---- Comprobantes (facturas emitidas con 1 a 5 ítems, mitad contado mitad cta. cte.) ----
        $t2 = microtime(true);
        $prodIds = DB::table('products')->where('business_id', $b->id)->pluck('price', 'id')->all(); $pk = array_keys($prodIds);
        $cliIds = DB::table('contacts')->where('business_id', $b->id)->where('type', 'customer')->pluck('id')->all();
        $numero = (int) DB::table('comprobantes')->where('business_id', $b->id)->where('tipo', 'FA')->max('numero');
        $inicio = now()->subMonths($meses)->startOfDay(); $dias = $inicio->diffInDays(now());
        $bar = $this->output->createProgressBar((int) ceil($nComp / 500));
        for ($k = 0; $k < $nComp; $k += 500) {
            $comps = []; $items = []; $cc = [];
            $primerId = (int) DB::table('comprobantes')->max('id') + 1;
            for ($i = $k; $i < min($nComp, $k + 500); $i++) {
                $fecha = $inicio->copy()->addDays(mt_rand(0, (int) $dias)); $cli = $cliIds[array_rand($cliIds)]; $ctaCte = $i % 2 === 0;
                $neto = 0; $iva = 0; $nItems = mt_rand(1, 5); $lineas = [];
                for ($j = 0; $j < $nItems; $j++) { $pid = $pk[mt_rand(0, count($pk) - 1)]; $cant = mt_rand(1, 20); $pu = (float) $prodIds[$pid]; $n = round($cant * $pu, 2); $v = round($n * 0.21, 2); $neto += $n; $iva += $v; $lineas[] = [$pid, $cant, $pu, $n, $v]; }
                $total = round($neto + $iva, 2); $id = $primerId + ($i - $k); $numero++;
                $comps[] = ['id' => $id, 'business_id' => $b->id, 'business_location_id' => $suc?->id, 'contact_id' => $cli, 'user_id' => $uid, 'punto_venta_id' => $pv?->id, 'direccion' => 'venta', 'tipo' => 'FA', 'punto_venta' => $pv?->numero ?? 1, 'numero' => $numero, 'fecha' => $fecha->toDateString(), 'fecha_vto' => $fecha->copy()->addDays(30)->toDateString(), 'condicion' => $ctaCte ? 'cta_cte' : 'contado', 'moneda' => 'ARS', 'cotizacion' => 1, 'neto' => round($neto, 2), 'iva' => round($iva, 2), 'total' => $total, 'saldo' => $ctaCte ? $total : 0, 'estado' => 'emitido', 'stock_impactado' => true, 'emitido_en' => $fecha->copy()->setTime(mt_rand(8, 19), mt_rand(0, 59)), 'created_at' => $now, 'updated_at' => $now];
                foreach ($lineas as $o => [$pid, $cant, $pu, $n, $v]) $items[] = ['comprobante_id' => $id, 'product_id' => $pid, 'descripcion' => 'Artículo ' . $pid, 'cantidad' => $cant, 'unidad' => 'un', 'precio_unit' => $pu, 'costo_unit' => round($pu / 1.45, 4), 'descuento' => 0, 'alicuota_iva' => 21, 'neto' => $n, 'iva' => $v, 'total' => round($n + $v, 2), 'orden' => $o, 'created_at' => $now, 'updated_at' => $now];
                if ($ctaCte) $cc[] = ['business_id' => $b->id, 'contact_id' => $cli, 'comprobante_id' => $id, 'fecha' => $fecha->toDateString(), 'fecha_vto' => $fecha->copy()->addDays(30)->toDateString(), 'tipo' => 'factura', 'concepto' => 'FA 0001-' . str_pad((string) $numero, 8, '0', STR_PAD_LEFT), 'debe' => $total, 'haber' => 0, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::transaction(function () use ($comps, $items, $cc) { DB::table('comprobantes')->insert($comps); foreach (array_chunk($items, 1000) as $ch) DB::table('comprobante_items')->insert($ch); if ($cc) DB::table('cuenta_corriente')->insert($cc); });
            $bar->advance();
        }
        $bar->finish(); $this->newLine();
        // Saldos de clientes coherentes con la cuenta corriente.
        DB::statement('UPDATE contacts SET balance = COALESCE((SELECT SUM(debe - haber) FROM cuenta_corriente WHERE cuenta_corriente.contact_id = contacts.id), 0) WHERE business_id = ?', [$b->id]);
        $this->info("Comprobantes: {$nComp} en " . round(microtime(true) - $t2, 1) . ' s · total ' . round(microtime(true) - $t0, 1) . ' s');
        return 0;
    }
}
