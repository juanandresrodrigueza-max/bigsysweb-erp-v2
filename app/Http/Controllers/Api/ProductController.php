<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection(
            Product::query()->paginate(20)
        );
    }

    public function store(Request $request): ProductResource
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'sku'         => 'required|string|unique:products',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'cost'        => 'nullable|numeric|min:0',
            'stock'       => 'nullable|integer|min:0',
            'stock_min'   => 'nullable|integer|min:0',
            'unit'        => 'nullable|string|max:20',
        ]);

        return new ProductResource(Product::create($data));
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product);
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'sku'         => 'sometimes|string|unique:products,sku,' . $product->id,
            'description' => 'nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'cost'        => 'nullable|numeric|min:0',
            'stock_min'   => 'nullable|integer|min:0',
            'unit'        => 'nullable|string|max:20',
            'active'      => 'sometimes|boolean',
        ]);

        $product->update($data);
        return new ProductResource($product);
    }

    public function destroy(Product $product): Response
    {
        $product->delete();
        return response()->noContent();
    }
}
