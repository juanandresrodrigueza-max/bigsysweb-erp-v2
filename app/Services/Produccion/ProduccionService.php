<?php

namespace App\Services\Produccion;

use App\Models\AuditLog;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Fórmulas (qué insumos lleva un producto elaborado) y órdenes de producción que consumen insumos y cargan el producto terminado.
class ProduccionService
{
    public function __construct(private StockService $stock) {}

    public function guardarFormula(array $d, ?Recipe $r = null): Recipe
    {
        return DB::transaction(function () use ($d, $r) {
            $r ??= new Recipe(['business_id' => Auth::user()->business_id]);
            $r->fill(['product_id' => $d['product_id'], 'name' => $d['name'], 'yield_quantity' => $d['yield_quantity'], 'yield_unit' => $d['yield_unit'] ?? 'un', 'instructions' => $d['instructions'] ?? null, 'tiempo_minutos' => $d['tiempo_minutos'] ?? null, 'is_active' => $d['is_active'] ?? true])->save();
            $r->items()->delete();
            foreach ($d['items'] as $it) {
                if (empty($it['product_id']) || (float) $it['quantity'] <= 0) continue;
                abort_if((int) $it['product_id'] === (int) $d['product_id'], 422, 'Un producto no puede ser insumo de sí mismo.');
                $r->items()->create(['product_id' => $it['product_id'], 'quantity' => $it['quantity'], 'unit' => $it['unit'] ?? 'un', 'notes' => $it['notes'] ?? null]);
            }
            $r->update(['costo_calculado' => $this->costoFormula($r)]);
            Product::where('id', $d['product_id'])->update(['tipo' => 'elaborado']);
            AuditLog::registrar($r->wasRecentlyCreated ? 'crear' : 'editar', $r, "Fórmula {$r->name}");
            return $r->fresh('items.product');
        });
    }

    // Costo de producir una tanda completa (yield_quantity unidades) a los costos actuales de los insumos.
    public function costoFormula(Recipe $r): float
    {
        return round($r->items()->with('product')->get()->sum(fn($i) => (float) $i->quantity * (float) ($i->product?->cost ?? 0)), 2);
    }

    public function crearOrden(array $d): ProductionOrder
    {
        return DB::transaction(function () use ($d) {
            $user = Auth::user();
            $receta = Recipe::with('items.product', 'product')->findOrFail($d['recipe_id']);
            $o = ProductionOrder::create([
                'business_id' => $user->business_id, 'business_location_id' => $user->current_location_id, 'deposito_id' => $d['deposito_id'] ?? Deposito::porDefecto($user->current_location_id)?->id,
                'recipe_id' => $receta->id, 'product_id' => $receta->product_id, 'user_id' => $user->id, 'numero' => (ProductionOrder::max('numero') ?? 0) + 1,
                'quantity' => $d['quantity'], 'status' => 'pending', 'scheduled_at' => $d['scheduled_at'] ?? null, 'notes' => $d['notes'] ?? null, 'comprobante_id' => $d['comprobante_id'] ?? null,
                'cost' => round($this->costoFormula($receta) / max((float) $receta->yield_quantity, 0.0001) * (float) $d['quantity'], 2),
            ]);
            AuditLog::registrar('crear', $o, "Orden de producción {$o->numeroFormateado()}: {$d['quantity']} {$receta->product->unit} de {$receta->product->name}");
            return $o;
        });
    }

    // Insumos que necesita la orden y cuánto hay en su depósito.
    public function insumosDe(ProductionOrder $o): array
    {
        $r = $o->recipe()->with('items.product')->first();
        $factor = (float) $o->quantity / max((float) $r->yield_quantity, 0.0001);
        return $r->items->map(function ($i) use ($factor, $o) {
            $nec = round((float) $i->quantity * $factor, 3);
            $hay = $i->product ? $i->product->stockEn($o->deposito_id) : 0;
            return ['product_id' => $i->product_id, 'nombre' => $i->product?->name, 'unit' => $i->product?->unit, 'necesario' => $nec, 'disponible' => $hay, 'falta' => max(0, round($nec - $hay, 3)), 'costo' => round($nec * (float) ($i->product?->cost ?? 0), 2)];
        })->all();
    }

    public function iniciar(ProductionOrder $o): void
    {
        abort_if($o->status !== 'pending', 422, 'La orden ya fue iniciada.');
        $o->update(['status' => 'in_progress', 'started_at' => now()]);
    }

    // Terminar: descuenta insumos del depósito, carga el producto terminado y actualiza su costo.
    public function terminar(ProductionOrder $o, ?float $producida = null): ProductionOrder
    {
        return DB::transaction(function () use ($o, $producida) {
            abort_if(! in_array($o->status, ['pending', 'in_progress'], true), 422, 'La orden no está abierta.');
            $producida ??= (float) $o->quantity;
            $deposito = Deposito::find($o->deposito_id) ?? Deposito::porDefecto($o->business_location_id);
            $insumos = $this->insumosDe($o);
            $faltan = array_filter($insumos, fn($i) => $i['falta'] > 0);
            if ($faltan) {
                throw ValidationException::withMessages(['insumos' => 'Faltan insumos: ' . collect($faltan)->map(fn($i) => "{$i['nombre']} ({$i['falta']} {$i['unit']})")->implode(', ')]);
            }
            $costoTotal = 0;
            foreach ($insumos as $i) {
                $p = Product::find($i['product_id']);
                $this->stock->mover($p, -$i['necesario'], $deposito, 'produccion', "Consumo {$o->numeroFormateado()}", $o, null, $o->business_location_id);
                $costoTotal += $i['costo'];
            }
            $prod = Product::findOrFail($o->product_id);
            $costoUnit = $producida > 0 ? round($costoTotal / $producida, 2) : 0;
            $this->stock->mover($prod, $producida, $deposito, 'produccion', "Producción {$o->numeroFormateado()}", $o, $costoUnit, $o->business_location_id);
            if ($costoUnit > 0) {
                $prod->forceFill(['cost' => $costoUnit])->save();
            }
            $o->update(['status' => 'completed', 'completed_at' => now(), 'started_at' => $o->started_at ?? now(), 'cantidad_producida' => $producida, 'cost' => round($costoTotal, 2)]);
            AuditLog::registrar('editar', $o, "Terminó {$o->numeroFormateado()}: {$producida} {$prod->unit} de {$prod->name} (costo " . number_format($costoTotal, 2, ',', '.') . ')');
            return $o->fresh();
        });
    }

    public function cancelar(ProductionOrder $o, string $motivo): void
    {
        abort_if($o->status === 'completed', 422, 'Una orden terminada no se cancela.');
        $o->update(['status' => 'cancelled', 'notes' => trim(($o->notes ?? '') . "\nCancelada: {$motivo}")]);
        AuditLog::registrar('anular', $o, "Canceló {$o->numeroFormateado()}: {$motivo}");
    }
}
