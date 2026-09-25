<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\Deposito;
use App\Models\Inventario;
use App\Models\Product;
use App\Models\StockDeposito;
use App\Models\StockMovement;
use App\Models\TransferenciaStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Único lugar por donde se mueve stock: mantiene el stock por depósito, el total del artículo y el kardex.
class StockService
{
    // Mueve $cantidad (positiva entra, negativa sale) en un depósito. Devuelve el movimiento.
    public function mover(Product $p, float $cantidad, ?Deposito $deposito, string $tipo, string $motivo, ?Model $origen = null, ?float $costoUnit = null, ?int $locationId = null, array $lote = []): ?StockMovement
    {
        $p = Product::lockForUpdate()->find($p->id);
        if (! $p || ! $p->controla_stock || abs($cantidad) < 0.0005) {
            return null;
        }
        $deposito ??= Deposito::porDefecto($locationId ?? Auth::user()?->current_location_id);

        $antes = (float) $p->stock;
        $p->stock = $antes + $cantidad;
        $p->save();

        if ($deposito) {
            $sd = StockDeposito::firstOrCreate(['product_id' => $p->id, 'deposito_id' => $deposito->id], ['business_id' => $p->business_id, 'cantidad' => 0]);
            $sd->cantidad = (float) $sd->cantidad + $cantidad;
            $sd->save();
        }

        // Partidas con lote / vencimiento / serie: entradas suman (o vuelven al lote de origen), salidas consumen FEFO o el lote elegido.
        $partidas = [];
        if ($p->perecedero || $p->seriado) {
            $ls = app(LotesService::class);
            if ($cantidad > 0 && ! empty($lote['devolver_de'])) $partidas = $ls->devolver($p, $deposito, $cantidad, $lote['devolver_de']);
            elseif ($cantidad > 0) $partidas = $ls->entrada($p, $deposito, $cantidad, $lote + ['costo' => $costoUnit, 'proveedor_id' => $origen instanceof \App\Models\Comprobante && $origen->direccion === 'compra' ? $origen->contact_id : null]);
            else $partidas = $ls->salida($p, $deposito, -$cantidad, $lote['serie'] ?? null, $lote);
        }
        $loteId = $partidas[0]['lote']->id ?? null;

        if (\App\Models\Canal::withoutGlobalScopes()->where('business_id', $p->business_id)->where('activo', true)->where('sync_stock', true)->exists()) { try { app(\App\Services\Canales\CanalesService::class)->empujarStock($p); } catch (\Throwable $e) {} }

        $mov = StockMovement::create([
            'business_id' => $p->business_id, 'business_location_id' => $deposito?->business_location_id ?? $locationId ?? Auth::user()?->current_location_id,
            'product_id' => $p->id, 'deposito_id' => $deposito?->id, 'lote_id' => $loteId, 'user_id' => Auth::id() ?? $p->business->owner_id,
            'type' => $tipo, 'quantity' => abs($cantidad), 'stock_before' => $antes, 'stock_after' => (float) $p->stock, 'costo_unit' => $costoUnit ?? (float) $p->cost,
            'reason' => $motivo, 'movable_id' => $origen?->getKey(), 'movable_type' => $origen ? get_class($origen) : null,
            // El kardex lleva la fecha del comprobante u orden que lo originó (si la tiene), no la de carga.
            'created_at' => ($origen && isset($origen->fecha) && $origen->fecha) ? \Carbon\Carbon::parse($origen->fecha)->setTimeFrom(now()) : now(),
        ]);
        if ($partidas) app(LotesService::class)->registrar($partidas, $cantidad > 0 ? 1 : -1, $mov, $origen, $motivo);
        // Ubicaciones (Fase 27.4): una salida descuenta de las ubicaciones lo que ya no alcanza a cubrir lo "sin ubicar".
        if ($cantidad < 0 && $deposito && \App\Models\Ubicacion::where('deposito_id', $deposito->id)->exists()) app(AlmacenService::class)->consumir($p, $deposito, array_map(fn($x) => $x['lote']->id, $partidas));
        return $mov;
    }

    // Atajos legibles desde los otros servicios.
    public function entrada(Product $p, float $cantidad, string $motivo, ?Model $origen = null, ?Deposito $deposito = null, ?float $costoUnit = null, ?int $locationId = null, array $lote = []): ?StockMovement
    {
        return $this->mover($p, abs($cantidad), $deposito, 'in', $motivo, $origen, $costoUnit, $locationId, $lote);
    }

    public function salida(Product $p, float $cantidad, string $motivo, ?Model $origen = null, ?Deposito $deposito = null, ?int $locationId = null): ?StockMovement
    {
        return $this->mover($p, -abs($cantidad), $deposito, 'out', $motivo, $origen, null, $locationId);
    }

    // Ajuste manual: lleva el stock del depósito a $nuevo y registra la diferencia.
    public function ajustar(Product $p, Deposito $deposito, float $nuevo, string $motivo, string $tipo = 'ajuste', ?Model $origen = null): ?StockMovement
    {
        $actual = $p->stockEn($deposito->id);
        $dif = round($nuevo - $actual, 3);
        if (abs($dif) < 0.0005) {
            return null;
        }
        $m = $this->mover($p, $dif, $deposito, $tipo, $motivo, $origen);
        if ($tipo === 'ajuste') {
            AuditLog::registrar('ajuste_stock', $p, "{$p->name}: {$actual} → {$nuevo} {$p->unit} en {$deposito->nombre} ({$motivo})");
        }
        return $m;
    }

    public function transferir(Deposito $origen, Deposito $destino, array $items, string $fecha, ?string $notas = null): TransferenciaStock
    {
        abort_if($origen->id === $destino->id, 422, 'El origen y el destino son el mismo depósito.');
        return DB::transaction(function () use ($origen, $destino, $items, $fecha, $notas) {
            $user = Auth::user();
            $t = TransferenciaStock::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'user_id' => $user->id,
                'numero' => (TransferenciaStock::max('numero') ?? 0) + 1, 'fecha' => $fecha, 'origen_id' => $origen->id, 'destino_id' => $destino->id, 'notas' => $notas,
            ]);
            foreach ($items as $it) {
                $p = Product::findOrFail($it['product_id']);
                $cant = (float) $it['cantidad'];
                if ($cant <= 0) continue;
                abort_if($p->stockEn($origen->id) + 0.0005 < $cant, 422, "No hay {$cant} {$p->unit} de {$p->name} en {$origen->nombre} (hay " . $p->stockEn($origen->id) . ').');
                $t->items()->create(['product_id' => $p->id, 'cantidad' => $cant]);
                $this->mover($p, -$cant, $origen, 'transferencia', "{$t->numeroFormateado()} → {$destino->nombre}", $t);
                $this->mover($p, $cant, $destino, 'transferencia', "{$t->numeroFormateado()} ← {$origen->nombre}", $t);
            }
            abort_if(! $t->items()->exists(), 422, 'La transferencia no tiene ítems.');
            AuditLog::registrar('crear', $t, "Transferencia {$t->numeroFormateado()} {$origen->nombre} → {$destino->nombre}");
            return $t;
        });
    }

    public function anularTransferencia(TransferenciaStock $t, string $motivo): void
    {
        DB::transaction(function () use ($t, $motivo) {
            abort_if($t->estado === 'anulada', 422, 'Ya está anulada.');
            foreach ($t->items as $it) {
                $p = $it->product;
                $this->mover($p, (float) $it->cantidad, $t->origen, 'transferencia', "Anulación {$t->numeroFormateado()}", $t);
                $this->mover($p, -(float) $it->cantidad, $t->destino, 'transferencia', "Anulación {$t->numeroFormateado()}", $t);
            }
            $t->update(['estado' => 'anulada', 'notas' => trim(($t->notas ?? '') . "\nAnulada: {$motivo}")]);
            AuditLog::registrar('anular', $t, "Anuló {$t->numeroFormateado()}: {$motivo}");
        });
    }

    // Inventario físico: recibe [product_id => contado] y ajusta cada diferencia en el depósito.
    public function cerrarInventario(Deposito $deposito, array $conteos, string $fecha, ?string $notas = null): Inventario
    {
        return DB::transaction(function () use ($deposito, $conteos, $fecha, $notas) {
            $user = Auth::user();
            $inv = Inventario::create([
                'business_id' => $user->business_id, 'business_location_id' => $deposito->business_location_id, 'deposito_id' => $deposito->id, 'user_id' => $user->id,
                'numero' => (Inventario::max('numero') ?? 0) + 1, 'fecha' => $fecha, 'notas' => $notas,
            ]);
            $contados = 0; $conDif = 0; $valor = 0;
            foreach ($conteos as $productId => $contado) {
                if ($contado === null || $contado === '') continue;
                $p = Product::find($productId);
                if (! $p) continue;
                $sistema = $p->stockEn($deposito->id);
                $dif = round((float) $contado - $sistema, 3);
                $inv->items()->create(['product_id' => $p->id, 'sistema' => $sistema, 'contado' => (float) $contado, 'diferencia' => $dif, 'costo_unit' => (float) $p->cost]);
                $contados++;
                if (abs($dif) >= 0.0005) {
                    $conDif++;
                    $valor += $dif * (float) $p->cost;
                    $this->ajustar($p, $deposito, (float) $contado, "Inventario {$inv->numeroFormateado()}", 'inventario', $inv);
                }
            }
            $inv->update(['items_contados' => $contados, 'items_con_diferencia' => $conDif, 'diferencia_valorizada' => round($valor, 2)]);
            AuditLog::registrar('inventario', $inv, "Inventario {$inv->numeroFormateado()} en {$deposito->nombre}: {$contados} contados, {$conDif} con diferencia");
            return $inv;
        });
    }

    // Recalcula el total del artículo a partir de los depósitos (por si algo quedó desfasado).
    public function sincronizarTotal(Product $p): void
    {
        $p->forceFill(['stock' => (float) $p->stocks()->sum('cantidad')])->save();
    }
}
