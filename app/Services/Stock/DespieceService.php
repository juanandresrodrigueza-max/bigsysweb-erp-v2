<?php

namespace App\Services\Stock;

use App\Models\AuditLog;
use App\Models\Deposito;
use App\Models\Despiece;
use App\Models\DespieceOperacion;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fase 27.3: despiece (media res, cuarto, cerdo, pollo). Sale la materia prima, entran los cortes, y el costo se reparte
// por valor de venta: cada corte carga costo en proporción a lo que vale (kg × precio), como se calcula en carnicería.
class DespieceService
{
    public function __construct(private StockService $stock) {}

    public function ejecutar(Despiece $d, float $kgEntrada, array $cortes, ?Deposito $dep = null, ?string $fecha = null, ?float $costoKg = null, bool $actualizarCostos = true, ?string $notas = null): DespieceOperacion
    {
        return DB::transaction(function () use ($d, $kgEntrada, $cortes, $dep, $fecha, $costoKg, $actualizarCostos, $notas) {
            $mp = $d->product; $dep ??= Deposito::porDefecto(Auth::user()?->current_location_id);
            $cortes = array_filter(array_map('floatval', $cortes), fn($k) => $k > 0);
            if (! $cortes) throw ValidationException::withMessages(['cortes' => 'Cargá los kilos de al menos un corte.']);
            $kgSalida = round(array_sum($cortes), 3);
            if ($kgSalida > $kgEntrada + 0.0005) throw ValidationException::withMessages(['cortes' => 'Los cortes pesan ' . number_format($kgSalida, 3, ',', '.') . " kg: más que los {$kgEntrada} kg que entraron."]);
            $costoKg ??= $mp->costoPesos();
            $costoTotal = round($kgEntrada * $costoKg, 2);
            $esperado = $d->cortes->pluck('rinde', 'product_id');
            $prods = Product::whereIn('id', array_keys($cortes))->get()->keyBy('id');
            $valor = fn($pid, $kg) => $kg * (float) ($prods[$pid]?->price ?? 0);
            $valorTotal = array_sum(array_map(fn($pid, $kg) => $valor($pid, $kg), array_keys($cortes), $cortes));
            $op = DespieceOperacion::create(['business_id' => $d->business_id, 'despiece_id' => $d->id, 'deposito_id' => $dep?->id, 'user_id' => Auth::id(), 'fecha' => $fecha ?: today()->toDateString(),
                'kg_entrada' => $kgEntrada, 'costo_total' => $costoTotal, 'kg_salida' => $kgSalida, 'merma_kg' => round($kgEntrada - $kgSalida, 3), 'notas' => $notas]);
            $this->stock->mover($mp, -$kgEntrada, $dep, 'produccion', "Despiece #{$op->id} {$d->nombre}", $op, $costoKg, null, ['permitir_vencidos' => true]);
            foreach ($cortes as $pid => $kg) {
                $p = $prods[$pid];
                // Sin precio de venta en ningún corte, el costo se reparte por peso.
                $parte = $valorTotal > 0 ? $valor($pid, $kg) / $valorTotal : $kg / $kgSalida;
                $ck = $kg > 0 ? round($costoTotal * $parte / $kg, 4) : 0;
                $op->items()->create(['product_id' => $pid, 'kg' => $kg, 'rinde_real' => round($kg / $kgEntrada * 100, 3), 'rinde_esperado' => $esperado[$pid] ?? null, 'precio_venta' => (float) $p->price, 'costo_kg' => $ck]);
                $this->stock->mover($p, $kg, $dep, 'produccion', "Despiece #{$op->id} {$d->nombre}", $op, $ck);
                if ($actualizarCostos) { $p->cost = round($ck, 2); $p->save(); }
            }
            AuditLog::registrar('crear', $op, "Despiece {$d->nombre}: {$kgEntrada} kg → {$kgSalida} kg en cortes, merma " . number_format($kgEntrada - $kgSalida, 3, ',', '.') . ' kg');
            return $op->load('items.product');
        });
    }

    public function resumen(DespieceOperacion $op): array
    {
        $op->loadMissing('items.product', 'despiece.product');
        $venta = $op->items->sum(fn($i) => (float) $i->kg * (float) $i->precio_venta);
        return ['id' => $op->id, 'fecha' => $op->fecha->format('d/m/Y'), 'plantilla' => $op->despiece?->nombre, 'materia' => $op->despiece?->product?->name, 'kg_entrada' => (float) $op->kg_entrada, 'kg_salida' => (float) $op->kg_salida,
            'merma_kg' => (float) $op->merma_kg, 'merma_pct' => (float) $op->kg_entrada > 0 ? round((float) $op->merma_kg / (float) $op->kg_entrada * 100, 2) : 0, 'costo_total' => (float) $op->costo_total,
            'venta_total' => round($venta, 2), 'margen_pct' => $venta > 0 ? round(($venta - (float) $op->costo_total) / $venta * 100, 1) : null,
            'items' => $op->items->map(fn($i) => ['producto' => $i->product?->name, 'kg' => (float) $i->kg, 'rinde_real' => (float) $i->rinde_real, 'rinde_esperado' => $i->rinde_esperado !== null ? (float) $i->rinde_esperado : null, 'precio_venta' => (float) $i->precio_venta, 'costo_kg' => (float) $i->costo_kg,
                'margen_pct' => (float) $i->precio_venta > 0 ? round(((float) $i->precio_venta - (float) $i->costo_kg) / (float) $i->precio_venta * 100, 1) : null])->values()];
    }
}
