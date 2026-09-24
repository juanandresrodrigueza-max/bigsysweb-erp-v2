<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            ProductionOrder::with(['recipe.product'])
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->paginate(25)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'recipe_id' => 'required|exists:recipes,id',
            'quantity' => 'required|numeric|min:0.0001',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $order = ProductionOrder::create($data);
        return response()->json($order->load('recipe.product'), 201);
    }

    public function show(ProductionOrder $productionOrder)
    {
        return response()->json($productionOrder->load(['recipe.product','recipe.items.material']));
    }

    public function start(ProductionOrder $productionOrder)
    {
        if ($productionOrder->status !== 'pending') {
            return response()->json(['message' => 'Solo órdenes pendientes pueden iniciarse'], 422);
        }

        // Verificar y descontar materias primas
        DB::transaction(function () use ($productionOrder) {
            $recipe = $productionOrder->recipe->load('items.material');
            $factor = $productionOrder->quantity / $recipe->yield_quantity;

            foreach ($recipe->items as $item) {
                $product = $item->material;
                $qty = $item->quantity * $factor;

                if ($product->stock < $qty) {
                    throw new \Exception("Stock insuficiente para {$product->name}: necesita {$qty}, hay {$product->stock}");
                }

                $before = $product->stock;
                $product->decrement('stock', $qty);

                StockMovement::create([
                    'business_id' => $productionOrder->business_id,
                    'product_id' => $product->id,
                    'type' => 'out',
                    'reason' => 'production',
                    'quantity' => $qty,
                    'before_quantity' => $before,
                    'after_quantity' => $before - $qty,
                    'movable_type' => ProductionOrder::class,
                    'movable_id' => $productionOrder->id,
                    'notes' => "Orden de producción #{$productionOrder->id}",
                ]);
            }

            $productionOrder->update(['status' => 'in_progress', 'started_at' => now()]);
        });

        return response()->json($productionOrder->fresh('recipe.product'));
    }

    public function complete(ProductionOrder $productionOrder)
    {
        if ($productionOrder->status !== 'in_progress') {
            return response()->json(['message' => 'Solo órdenes en progreso pueden completarse'], 422);
        }

        DB::transaction(function () use ($productionOrder) {
            $recipe = $productionOrder->recipe;
            $outputProduct = $recipe->product;
            $producedQty = $productionOrder->quantity;

            $before = $outputProduct->stock;
            $outputProduct->increment('stock', $producedQty);

            StockMovement::create([
                'business_id' => $productionOrder->business_id,
                'product_id' => $outputProduct->id,
                'type' => 'in',
                'reason' => 'production',
                'quantity' => $producedQty,
                'before_quantity' => $before,
                'after_quantity' => $before + $producedQty,
                'movable_type' => ProductionOrder::class,
                'movable_id' => $productionOrder->id,
                'notes' => "Producción completada orden #{$productionOrder->id}",
            ]);

            $productionOrder->update(['status' => 'completed', 'completed_at' => now()]);
        });

        return response()->json($productionOrder->fresh('recipe.product'));
    }

    public function cancel(ProductionOrder $productionOrder)
    {
        if (in_array($productionOrder->status, ['completed','cancelled'])) {
            return response()->json(['message' => 'No se puede cancelar en este estado'], 422);
        }
        $productionOrder->update(['status' => 'cancelled']);
        return response()->json($productionOrder);
    }
}
