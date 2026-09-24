<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index()
    {
        return response()->json(Recipe::with(['product','items.material'])->paginate(25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'yield_quantity' => 'required|numeric|min:0.0001',
            'yield_unit' => 'required|string|max:20',
            'instructions' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit' => 'required|string|max:20',
            'items.*.notes' => 'nullable|string',
        ]);

        $recipe = Recipe::create($data);
        $recipe->items()->createMany($data['items']);

        return response()->json($recipe->load(['product','items.material']), 201);
    }

    public function show(Recipe $recipe)
    {
        return response()->json($recipe->load(['product','items.material']));
    }

    public function update(Request $request, Recipe $recipe)
    {
        $data = $request->validate([
            'name' => 'string|max:255',
            'yield_quantity' => 'numeric|min:0.0001',
            'yield_unit' => 'string|max:20',
            'instructions' => 'nullable|string',
            'is_active' => 'boolean',
            'items' => 'array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit' => 'required_with:items|string|max:20',
        ]);

        $recipe->update($data);

        if (isset($data['items'])) {
            $recipe->items()->delete();
            $recipe->items()->createMany($data['items']);
        }

        return response()->json($recipe->fresh(['product','items.material']));
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();
        return response()->json(null, 204);
    }
}
