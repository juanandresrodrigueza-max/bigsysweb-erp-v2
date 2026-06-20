<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SaleResource::collection(
            Sale::with(['customer', 'items.product'])->paginate(20)
        );
    }

    public function store(Request $request): SaleResource
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'notes'       => 'nullable|string',
            'discount'    => 'nullable|numeric|min:0',
            'tax'         => 'nullable|numeric|min:0',
            'items'       => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount'   => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $subtotal = collect($data['items'])->sum(fn($i) =>
                ($i['unit_price'] * $i['quantity']) - ($i['discount'] ?? 0)
            );

            $sale = Sale::create([
                'customer_id' => $data['customer_id'],
                'user_id'     => $request->user()->id,
                'notes'       => $data['notes'] ?? null,
                'discount'    => $data['discount'] ?? 0,
                'tax'         => $data['tax'] ?? 0,
                'subtotal'    => $subtotal,
                'total'       => $subtotal - ($data['discount'] ?? 0) + ($data['tax'] ?? 0),
                'status'      => 'pending',
            ]);

            foreach ($data['items'] as $item) {
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                $itemSubtotal = ($item['unit_price'] * $item['quantity']) - ($item['discount'] ?? 0);

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount'   => $item['discount'] ?? 0,
                    'subtotal'   => $itemSubtotal,
                ]);

                $before = $product->stock;
                $product->decrement('stock', $item['quantity']);

                StockMovement::create([
                    'product_id'  => $product->id,
                    'user_id'     => $request->user()->id,
                    'type'        => 'out',
                    'quantity'    => $item['quantity'],
                    'stock_before'=> $before,
                    'stock_after' => $before - $item['quantity'],
                    'reason'      => 'sale',
                    'movable_id'  => $sale->id,
                    'movable_type'=> Sale::class,
                ]);
            }

            return new SaleResource($sale->load(['customer', 'items.product']));
        });
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load(['customer', 'user', 'items.product']));
    }

    public function update(Request $request, Sale $sale): SaleResource
    {
        $data = $request->validate([
            'status' => 'sometimes|in:pending,confirmed,cancelled',
            'notes'  => 'nullable|string',
        ]);

        if (isset($data['status']) && $data['status'] === 'confirmed') {
            $data['confirmed_at'] = now();
        }

        $sale->update($data);
        return new SaleResource($sale->load(['customer', 'items.product']));
    }

    public function destroy(Sale $sale): Response
    {
        $sale->delete();
        return response()->noContent();
    }
}
