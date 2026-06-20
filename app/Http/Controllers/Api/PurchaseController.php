<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::with(['contact', 'user'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->payment_status, fn($q) => $q->where('payment_status', $request->payment_status))
            ->latest()
            ->paginate(20);

        return response()->json($purchases);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_id'           => 'required|exists:contacts,id',
            'business_location_id' => 'nullable|exists:business_locations,id',
            'invoice_number'       => 'nullable|string|max:50',
            'invoice_date'         => 'nullable|date',
            'due_date'             => 'nullable|date',
            'discount'             => 'nullable|numeric|min:0',
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.unit_cost'    => 'required|numeric|min:0',
            'items.*.discount'     => 'nullable|numeric|min:0',
            'items.*.tax'          => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $subtotal = collect($data['items'])->sum(fn($i) =>
                ($i['unit_cost'] * $i['quantity']) - ($i['discount'] ?? 0)
            );

            $purchase = Purchase::create([
                'contact_id'           => $data['contact_id'],
                'user_id'              => $request->user()->id,
                'business_location_id' => $data['business_location_id'] ?? null,
                'invoice_number'       => $data['invoice_number'] ?? null,
                'invoice_date'         => $data['invoice_date'] ?? today(),
                'due_date'             => $data['due_date'] ?? null,
                'discount'             => $data['discount'] ?? 0,
                'subtotal'             => $subtotal,
                'total'                => $subtotal - ($data['discount'] ?? 0),
                'status'               => 'received',
                'payment_status'       => 'pending',
                'notes'                => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $product  = Product::lockForUpdate()->findOrFail($item['product_id']);
                $subtotal = ($item['unit_cost'] * $item['quantity']) - ($item['discount'] ?? 0);

                $purchase->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'unit_cost'  => $item['unit_cost'],
                    'discount'   => $item['discount'] ?? 0,
                    'tax'        => $item['tax'] ?? 0,
                    'subtotal'   => $subtotal,
                ]);

                $before = $product->stock;
                $product->increment('stock', $item['quantity']);
                $product->update(['cost' => $item['unit_cost']]);

                StockMovement::create([
                    'business_id'  => $request->user()->business_id,
                    'product_id'   => $product->id,
                    'user_id'      => $request->user()->id,
                    'type'         => 'in',
                    'quantity'     => $item['quantity'],
                    'stock_before' => $before,
                    'stock_after'  => $before + $item['quantity'],
                    'reason'       => 'purchase',
                    'movable_id'   => $purchase->id,
                    'movable_type' => Purchase::class,
                ]);
            }

            return response()->json($purchase->load(['contact', 'items.product']), 201);
        });
    }

    public function show(Purchase $purchase): JsonResponse
    {
        return response()->json($purchase->load(['contact', 'user', 'items.product', 'location']));
    }

    public function update(Request $request, Purchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'status'         => 'sometimes|in:pending,received,cancelled',
            'payment_status' => 'sometimes|in:pending,partial,paid',
            'amount_paid'    => 'sometimes|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $purchase->update($data);

        return response()->json($purchase->load(['contact', 'items.product']));
    }

    public function destroy(Purchase $purchase): Response
    {
        $purchase->delete();
        return response()->noContent();
    }
}
