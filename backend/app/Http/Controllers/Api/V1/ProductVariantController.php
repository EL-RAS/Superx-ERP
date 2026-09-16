<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductVariant::query();

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('attribute1_value')) {
            $query->where('attribute1_value', $request->input('attribute1_value'));
        }

        if ($request->has('attribute2_value')) {
            $query->where('attribute2_value', $request->input('attribute2_value'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'ilike', "%{$search}%")
                    ->orWhere('barcode', 'ilike', "%{$search}%")
                    ->orWhere('attribute1_value', 'ilike', "%{$search}%")
                    ->orWhere('attribute2_value', 'ilike', "%{$search}%");
            });
        }

        $variants = $query->with('product:id,name,sku,price,cost')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($variants);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'sku' => [
                'required',
                'string',
                Rule::unique('product_variants', 'sku')->where(fn ($q) => $q->where('business_id', $request->user()->business_id)),
            ],
            'barcode' => 'nullable|string|max:100',
            'attribute1_name' => 'nullable|string|max:100',
            'attribute1_value' => 'nullable|string|max:100',
            'attribute2_name' => 'nullable|string|max:100',
            'attribute2_value' => 'nullable|string|max:100',
            'price_adjustment' => 'nullable|numeric',
            'cost_adjustment' => 'nullable|numeric',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['is_active'] ??= true;
        $validated['stock_quantity'] ??= 0;

        $variant = ProductVariant::create($validated);

        $variant->load('product:id,name,sku,price,cost');

        return response()->json($variant, 201);
    }

    public function show(ProductVariant $productVariant): JsonResponse
    {
        $productVariant->load('product:id,name,sku,price,cost');

        return response()->json($productVariant);
    }

    public function update(Request $request, ProductVariant $productVariant): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['sometimes', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'sku' => [
                'sometimes',
                'string',
                Rule::unique('product_variants', 'sku')
                    ->where(fn ($q) => $q->where('business_id', $request->user()->business_id))
                    ->ignore($productVariant->id),
            ],
            'barcode' => 'nullable|string|max:100',
            'attribute1_name' => 'nullable|string|max:100',
            'attribute1_value' => 'nullable|string|max:100',
            'attribute2_name' => 'nullable|string|max:100',
            'attribute2_value' => 'nullable|string|max:100',
            'price_adjustment' => 'nullable|numeric',
            'cost_adjustment' => 'nullable|numeric',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $productVariant->update($validated);

        $productVariant->load('product:id,name,sku,price,cost');

        return response()->json($productVariant);
    }

    public function destroy(ProductVariant $productVariant): JsonResponse
    {
        $productVariant->delete();

        return response()->json(['message' => 'Product variant deleted.']);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'attribute1_name' => 'required|string|max:100',
            'attribute1_values' => 'required|array|min:1',
            'attribute1_values.*' => 'required|string|max:100',
            'attribute2_name' => 'nullable|string|max:100',
            'attribute2_values' => 'nullable|array|min:1',
            'attribute2_values.*' => 'nullable|string|max:100',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $businessId = $request->user()->business_id;

        $attr1Name = $validated['attribute1_name'];
        $attr1Values = $validated['attribute1_values'];
        $attr2Name = $validated['attribute2_name'] ?? null;
        $attr2Values = $validated['attribute2_values'] ?? [null];

        $created = [];

        foreach ($attr1Values as $val1) {
            foreach ($attr2Values as $val2) {
                $sku = $product->sku . '-' . strtoupper($val1) . ($val2 ? '-' . strtoupper($val2) : '');

                if (ProductVariant::where('business_id', $businessId)->where('sku', $sku)->exists()) {
                    continue;
                }

                $variant = ProductVariant::create([
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'attribute1_name' => $attr1Name,
                    'attribute1_value' => $val1,
                    'attribute2_name' => $attr2Name,
                    'attribute2_value' => $val2,
                    'stock_quantity' => 0,
                    'is_active' => true,
                ]);

                $created[] = $variant;
            }
        }

        return response()->json(['data' => $created], 201);
    }
}
