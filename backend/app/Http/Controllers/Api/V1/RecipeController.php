<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Recipe::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $recipes = $query->with('product:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($recipes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'name' => 'required|string',
            'serving_size' => 'nullable|string',
            'prep_time_minutes' => 'nullable|integer|min:0',
            'cost_per_serving' => 'nullable|numeric|min:0',
            'instructions' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'ingredients' => 'nullable|array',
            'ingredients.*.product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'ingredients.*.quantity' => 'required|numeric|min:0',
            'ingredients.*.unit' => 'required|string',
            'ingredients.*.cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $recipe = Recipe::create([
                'business_id' => $request->user()->business_id,
                'product_id' => $validated['product_id'],
                'name' => $validated['name'],
                'serving_size' => $validated['serving_size'] ?? null,
                'prep_time_minutes' => $validated['prep_time_minutes'] ?? null,
                'cost_per_serving' => $validated['cost_per_serving'] ?? null,
                'instructions' => $validated['instructions'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            if (!empty($validated['ingredients'])) {
                foreach ($validated['ingredients'] as $ingredient) {
                    RecipeIngredient::create([
                        'business_id' => $request->user()->business_id,
                        'recipe_id' => $recipe->id,
                        'product_id' => $ingredient['product_id'],
                        'quantity' => $ingredient['quantity'],
                        'unit' => $ingredient['unit'],
                        'cost_per_unit' => $ingredient['cost_per_unit'] ?? null,
                    ]);
                }
            }

            $recipe->load('ingredients.product:id,name', 'product:id,name');

            return response()->json($recipe, 201);
        });
    }

    public function show(Recipe $recipe): JsonResponse
    {
        $recipe->load('ingredients.product:id,name', 'product:id,name');

        return response()->json($recipe);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['sometimes', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'name' => 'sometimes|string',
            'serving_size' => 'nullable|string',
            'prep_time_minutes' => 'nullable|integer|min:0',
            'cost_per_serving' => 'nullable|numeric|min:0',
            'instructions' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'ingredients' => 'nullable|array',
            'ingredients.*.product_id' => ['required_with:ingredients', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0',
            'ingredients.*.unit' => 'required_with:ingredients|string',
            'ingredients.*.cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated, $recipe) {
            $recipeFields = collect($validated)->except('ingredients')->filter()->toArray();
            if (!empty($recipeFields)) {
                $recipe->update($recipeFields);
            }

            if (isset($validated['ingredients'])) {
                $recipe->ingredients()->delete();

                foreach ($validated['ingredients'] as $ingredient) {
                    RecipeIngredient::create([
                        'business_id' => $recipe->business_id,
                        'recipe_id' => $recipe->id,
                        'product_id' => $ingredient['product_id'],
                        'quantity' => $ingredient['quantity'],
                        'unit' => $ingredient['unit'],
                        'cost_per_unit' => $ingredient['cost_per_unit'] ?? null,
                    ]);
                }
            }

            $recipe->load('ingredients.product:id,name', 'product:id,name');

            return response()->json($recipe);
        });
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->ingredients()->delete();
        $recipe->delete();

        return response()->json(['message' => 'Recipe deleted.']);
    }
}
