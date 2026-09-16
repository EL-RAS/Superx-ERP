<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhere('barcode', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }

        if ($request->has('category_id')) {
            $category = Category::with('children')->find($request->input('category_id'));

            if ($category) {
                $query->whereIn('category_id', $category->descendantIds());
            }
        }

        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        $products = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        $products->through(function ($product) {
            $batchStock = ProductBatch::where('product_id', $product->id)
                ->selectRaw('COALESCE(SUM(quantity - quantity_sold - quantity_returned), 0) as total')
                ->value('total');
            $product->setAttribute('batch_stock', (float) $batchStock);

            return $product;
        });

        return response()->json($products);
    }

    /**
     * Resolve and persist category_id (business-type scoped), keeping the legacy
     * `category` string column in sync for older consumers.
     */
    protected function normalizeCategory(array $validated, Request $request): array
    {
        if (! array_key_exists('category_id', $validated)) {
            return $validated;
        }

        $business = Business::with('businessType')->find($request->user()->business_id);

        if ($validated['category_id'] === null || $validated['category_id'] === '') {
            if (! isset($validated['category']) || $validated['category'] === null || $validated['category'] === '') {
                $validated['category'] = null;
            }

            return $validated;
        }

        $category = Category::where('id', (int) $validated['category_id'])
            ->where('business_type_id', $business?->business_type_id)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => ['The selected category is invalid.'],
            ]);
        }

        $validated['category_id'] = $category->id;

        if (! isset($validated['category']) || $validated['category'] === null || $validated['category'] === '') {
            $validated['category'] = $category->name;
        }

        return $validated;
    }

    protected function generateSku(Request $request): string
    {
        $business = Business::with('businessType')->find($request->user()->business_id);
        $typeSlug = $business?->businessType?->slug ?? 'gen';
        $typeCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $typeSlug), 0, 4));

        $categoryCode = 'GEN';
        if ($request->filled('category')) {
            $cat = Category::where('name', $request->input('category'))->first();
            if ($cat) {
                $categoryCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $cat->name), 0, 3));
            } else {
                $categoryCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $request->input('category')), 0, 3));
            }
        }

        $count = Product::where('business_id', $request->user()->business_id)->count();

        return sprintf('%s-%s-%04d', $typeCode, $categoryCode, $count + 1);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'barcode' => ['nullable', 'regex:/^\d{13}$/'],
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'is_on_sale' => 'boolean',
            'cost' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'has_expiry' => 'boolean',
            'has_batch' => 'boolean',
            'is_weighable' => 'boolean',
            'is_active' => 'boolean',
            'stock_quantity' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'storage_location' => 'nullable|string|max:255',
            'image_url' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($request->has('status') && ! $request->has('is_active')) {
            $validated['is_active'] = $request->input('status') === 'active';
        }

        if (isset($validated['sale_price']) && (float) $validated['sale_price'] <= 0) {
            $validated['sale_price'] = null;
        }
        if (($validated['is_on_sale'] ?? false) && ($validated['sale_price'] ?? null) === null) {
            $validated['is_on_sale'] = false;
        }

        $validated = $this->normalizeCategory($validated, $request);

        if (! isset($validated['tax_rate']) || $validated['tax_rate'] === null || $validated['tax_rate'] === 0) {
            $businessSettings = $request->user()->business->mergedSettings();
            $validated['tax_rate'] = $businessSettings['default_tax_rate'] ?? 16;
        }

        $validated['has_expiry'] = true;
        $validated['has_batch'] = true;
        $validated['created_by'] = $request->user()->id;

        if (empty($validated['sku'])) {
            $validated['sku'] = $this->generateSku($request);
        }

        if (isset($validated['stock_quantity'])) {
            $validated['stock_quantity'] = round((float) $validated['stock_quantity']);
        }

        Log::info('Product store payload', $validated);

        try {
            DB::beginTransaction();
            $product = Product::create($validated);
            DB::commit();
            Log::info('Product created', ['id' => $product->id, 'stock_quantity' => $product->stock_quantity]);

            return response()->json($product, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Product creation failed', [
                'error' => $e->getMessage(),
                'payload' => $validated,
            ]);

            return response()->json(['message' => 'Failed to create product.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'nullable|string|max:100',
            'barcode' => ['nullable', 'regex:/^\d{13}$/'],
            'price' => 'sometimes|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'is_on_sale' => 'sometimes|boolean',
            'cost' => 'sometimes|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'has_expiry' => 'boolean',
            'has_batch' => 'boolean',
            'is_weighable' => 'boolean',
            'is_active' => 'sometimes|boolean',
            'stock_quantity' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'storage_location' => 'nullable|string|max:255',
            'image_url' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($request->has('status') && ! $request->has('is_active')) {
            $validated['is_active'] = $request->input('status') === 'active';
        }

        if (isset($validated['sale_price']) && (float) $validated['sale_price'] <= 0) {
            $validated['sale_price'] = null;
        }
        if (($validated['is_on_sale'] ?? $product->is_on_sale) && ($validated['sale_price'] ?? $product->sale_price) === null) {
            $validated['is_on_sale'] = false;
        }

        $validated = $this->normalizeCategory($validated, $request);

        $validated['has_expiry'] = true;
        $validated['has_batch'] = true;

        if (isset($validated['stock_quantity'])) {
            $validated['stock_quantity'] = round((float) $validated['stock_quantity']);
        }

        Log::info('Product update payload', ['product_id' => $product->id, 'stock_quantity' => $validated['stock_quantity'] ?? $product->stock_quantity, 'payload' => $validated]);

        try {
            DB::beginTransaction();
            $product->update($validated);

            if (isset($validated['stock_quantity'])) {
                $product->recalculateStockQuantity();
            }
            DB::commit();
            Log::info('Product updated', ['id' => $product->id, 'stock_quantity' => $product->fresh()->stock_quantity]);

            return response()->json($product->fresh());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Product update failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'payload' => $validated,
            ]);

            return response()->json(['message' => 'Failed to update product.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    public function byBarcode(Request $request): JsonResponse
    {
        $request->validate([
            'barcode' => 'required|regex:/^\d{13}$/',
        ]);

        $barcode = $request->input('barcode');

        $product = Product::where('barcode', $barcode)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            $settings = $request->user()->business->mergedSettings();
            $scaleParsing = (bool) ($settings['scale_barcode_parsing'] ?? false);
            $scalePrefix = (string) ($settings['scale_barcode_prefix'] ?? '20');
            $weight = null;

            if ($scaleParsing && $scalePrefix !== '' && str_starts_with($barcode, $scalePrefix)) {
                $grams = (int) substr($barcode, -5);
                $code = strlen($scalePrefix) < 8
                    ? substr($barcode, strlen($scalePrefix), 13 - strlen($scalePrefix) - 5)
                    : '';

                if ($grams > 0 && $code !== '') {
                    $product = Product::where('barcode', $code)
                        ->where('is_active', true)
                        ->first();

                    if ($product) {
                        $weight = round($grams / 1000, 3);
                    }
                }
            }

            if (! $product) {
                return response()->json(['message' => 'Product not found.'], 404);
            }

            if ($weight !== null) {
                $product->setAttribute('scale_weight', $weight);
            }

            return response()->json($product);
        }

        return response()->json($product);
    }

    /**
     * Bulk product import from an uploaded .xlsx or .csv file.
     * Upserts rows keyed by barcode.
     */
    public function import(Request $request, ProductImportService $import): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:5120',
        ]);

        try {
            $businessSettings = $request->user()->business->mergedSettings();
            $result = $import->import(
                $validated['file']->getRealPath(),
                $validated['file']->getClientOriginalExtension(),
                $request->user()->business_id,
                $request->user()->id,
                (float) ($businessSettings['default_tax_rate'] ?? 16),
            );

            return response()->json([
                'message' => "Imported {$result['created']} new products and updated {$result['updated']} existing.",
                ...$result,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Product bulk import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import products: '.$this->importFailureReason($e),
                'errors' => [$this->importFailureReason($e)],
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Translate a low-level import exception into a concise, user-facing reason.
     */
    private function importFailureReason(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'null value in column "expiry_date"')) {
            return 'Missing expiry date for a batch-managed product. Add an expiry_date column to the file or supply expiries for each row.';
        }
        if (str_contains($message, 'not-null constraint')) {
            return 'The import is missing a required field (a non-nullable column came back empty). Review the file for missing required values.';
        }
        if (str_contains($message, 'duplicate key') || str_contains($message, 'unique constraint')) {
            return 'A product in the file conflicts with an existing unique value (SKU / batch). Adjust and re-upload.';
        }
        if (str_contains($message, 'foreign key') || str_contains($message, 'violates foreign key')) {
            return 'A value in the file references a record that does not exist (e.g. supplier). Check supplier codes and re-upload.';

        }

        // Fall back to the underlying message for anything unexpected.
        return $message;
    }

    /**
     * POS on-the-fly product creation for unknown barcodes.
     * Creates a light product (single unit, sellable) and returns it so the
     * cashier can ring it immediately.
     */
    public function quickAdd(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'barcode' => ['nullable', 'regex:/^\d{13}$/'],
            'selling_price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'unit' => 'nullable|string|max:50',
        ]);

        // An unknown barcode that later appears via autofill must be caught here.
        if ($validated['barcode'] ?? null) {
            $existing = Product::where('barcode', $validated['barcode'])
                ->where('is_active', true)
                ->first();

            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $validated = $this->normalizeCategory($validated, $request);
        $validated['price'] = $validated['selling_price'];
        unset($validated['selling_price']);

        if (! isset($validated['tax_rate']) || $validated['tax_rate'] === null || $validated['tax_rate'] === 0) {
            $businessSettings = $request->user()->business->mergedSettings();
            $validated['tax_rate'] = $businessSettings['default_tax_rate'] ?? 16;
        }

        $validated['cost'] = $validated['cost'] ?? 0;
        $validated['unit'] = $validated['unit'] ?? 'pcs';
        $validated['min_stock'] = 0;
        $validated['stock_quantity'] = 1;
        $validated['has_expiry'] = true;
        $validated['has_batch'] = true;
        $validated['is_active'] = true;
        $validated['created_by'] = $request->user()->id;

        if (empty($validated['sku'])) {
            $validated['sku'] = $this->generateSku($request);
        }

        try {
            DB::beginTransaction();
            $product = Product::create($validated);

            // Back the unit with a real batch so POS / FEFO deduction can
            // actually sell it (otherwise "Insufficient batch stock").
            ProductBatch::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'batch_number' => 'IMP-INIT-'.$product->id.'-1',
                'quantity' => 1,
                'cost_per_unit' => round((float) $validated['cost'], 2),
                'total_cost' => round((float) $validated['cost'], 2),
                'selling_price' => round((float) $validated['price'], 2),
                'received_date' => now()->toDateString(),
                'is_active' => true,
            ]);
            $product->recalculateStockQuantity();
            DB::commit();

            Log::info('POS quick-add product created', ['id' => $product->id, 'barcode' => $product->barcode]);

            return response()->json($product, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS quick-add failed', [
                'error' => $e->getMessage(),
                'payload' => $validated,
            ]);

            return response()->json(['message' => 'Failed to create product.', 'error' => $e->getMessage()], 500);
        }
    }
}
