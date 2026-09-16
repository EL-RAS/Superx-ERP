<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function __construct(
        protected BusinessContext $businessContext,
    ) {}

    /**
     * Business-type id for the current request's business. Categories are
     * shared across all businesses of the same business type.
     */
    private function businessTypeId(): int
    {
        return $this->businessContext->business()->business_type_id;
    }

    /**
     * Resolve a category scoped to the current business type, or 404.
     */
    private function scopeOrAbort(int $id): Category
    {
        $category = Category::where('id', $id)
            ->where('business_type_id', $this->businessTypeId())
            ->first();

        if (! $category) {
            abort(404);
        }

        return $category;
    }

    public function index(): JsonResponse
    {
        $categories = Category::where('business_type_id', $this->businessTypeId())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->withCount('products as linked_products_count')
            ->get(['id', 'name', 'name_ar', 'color', 'parent_id', 'sort_order']);

        return response()->json($categories);
    }

    public function show(Category $category): JsonResponse
    {
        $category = $this->scopeOrAbort($category->id);
        $category->loadCount('products as linked_products_count');

        return response()->json($category);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7',
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_type_id', $this->businessTypeId())],
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $maxSort = Category::where('business_type_id', $this->businessTypeId())
            ->max('sort_order') ?? 0;

        $category = Category::create([
            'business_type_id' => $this->businessTypeId(),
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'name_ar' => $validated['name_ar'] ?? null,
            'color' => $validated['color'] ?? null,
            'sort_order' => $validated['sort_order'] ?? $maxSort + 1,
        ]);

        $category->loadCount('products as linked_products_count');

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $category = $this->scopeOrAbort($category->id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7',
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_type_id', $this->businessTypeId())],
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if (isset($validated['parent_id']) && (int) $validated['parent_id'] === $category->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be its own parent.'],
            ]);
        }

        if (isset($validated['parent_id']) && in_array((int) $validated['parent_id'], $category->descendantIds(), true)) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be moved under one of its own sub-categories.'],
            ]);
        }

        $nameChanged = isset($validated['name']) && $validated['name'] !== $category->name;

        DB::transaction(function () use ($category, $validated, $nameChanged) {
            $category->update($validated);

            if ($nameChanged) {
                // Keep the denormalized products.category string in sync so
                // POS / promotions / sales-category buckets see the rename.
                Product::where('category_id', $category->id)
                    ->update(['category' => $category->fresh()->name]);
            }
        });

        $category->loadCount('products as linked_products_count');

        return response()->json($category->fresh());
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $category = $this->scopeOrAbort($category->id);

        $validated = $request->validate([
            'reassign_to' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_type_id', $this->businessTypeId())],
        ]);

        $targetId = $validated['reassign_to'] ?? null;

        if ($targetId && (int) $targetId === $category->id) {
            throw ValidationException::withMessages([
                'reassign_to' => ['Products cannot be reassigned to the category being deleted.'],
            ]);
        }

        DB::transaction(function () use ($category, $targetId) {
            $update = $targetId
                ? ['category_id' => (int) $targetId, 'category' => Category::whereKey($targetId)->value('name')]
                : ['category_id' => null, 'category' => null];

            Product::where('category_id', $category->id)->update($update);

            // Hard-delete. Products were reassigned explicitly above and any
            // child categories are detached to top-level via ON DELETE SET NULL.
            $category->forceDelete();
        });

        return response()->json(['message' => 'Category deleted.']);
    }
}
