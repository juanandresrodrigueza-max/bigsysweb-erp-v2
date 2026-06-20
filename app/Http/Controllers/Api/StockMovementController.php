<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StockMovementResource::collection(
            StockMovement::with(['product', 'user'])->latest()->paginate(20)
        );
    }

    public function store(Request $request): StockMovementResource
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type'       => 'required|in:in,out,adjustment',
            'quantity'   => 'required|integer|min:1',
            'reason'     => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $product = Product::lockForUpdate()->findOrFail($data['product_id']);
            $before  = $product->stock;
            $after   = $data['type'] === 'in'
                ? $before + $data['quantity']
                : $before - $data['quantity'];

            $product->update(['stock' => $after]);

            $movement = StockMovement::create([
                'product_id'   => $product->id,
                'user_id'      => $request->user()->id,
                'type'         => $data['type'],
                'quantity'     => $data['quantity'],
                'stock_before' => $before,
                'stock_after'  => $after,
                'reason'       => $data['reason'] ?? null,
                'movable_id'   => 0,
                'movable_type' => StockMovement::class,
            ]);

            return new StockMovementResource($movement->load(['product', 'user']));
        });
    }
}
