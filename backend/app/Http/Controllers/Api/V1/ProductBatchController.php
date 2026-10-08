<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\StockMovement;
use App\Rules\ProductQuantity;
use App\Services\AccountingService;
use App\Services\InventorySyncService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductBatch::query()->with([
            'product:id,name,sku,unit,has_batch',
            'supplier:id,name',
            'goodsReceipt:id,receipt_number,supplier_id',
        ]);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('batch_number', 'ilike', "%{$search}%")
                    ->orWhereHas('product', function ($pq) use ($search) {
                        $pq->where('name', 'ilike', "%{$search}%")
                            ->orWhere('sku', 'ilike', "%{$search}%")
                            ->orWhere('barcode', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('expiry_date_from')) {
            $query->where('expiry_date', '>=', $request->input('expiry_date_from'));
        }

        if ($request->has('expiry_date_to')) {
            $query->where('expiry_date', '<=', $request->input('expiry_date_to'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('expiring_soon')) {
            $days = (int) $request->input('expiring_soon', (int) ($request->user()->business->mergedSettings()['expiry_warning_days'] ?? 30));
            $query->where('expiry_date', '<=', now()->addDays($days))
                ->where('expiry_date', '>=', now())
                ->where('is_active', true);
        }

        if ($request->has('expired')) {
            $query->where('expiry_date', '<', now());
        }

        $batches = $query->orderBy('expiry_date', 'asc')
            ->paginate($request->integer('per_page', 10));

        $today = now()->startOfDay();
        $warningDays = (int) ($request->user()->business->mergedSettings()['expiry_warning_days'] ?? 30);
        $cutoff = $today->copy()->addDays($warningDays);

        // Valuation is computed per batch from on-hand stock (net of FEFO
        // sales and returns) at the batch unit cost. Expired stock is valued
        // separately; "active" covers every non-expired batch, including the
        // expiring-soon window (so active is the superset of expiring_soon).
        $summary = [
            'active_value' => 0.0,
            'expiring_soon_value' => 0.0,
            'expired_value' => 0.0,
        ];

        ProductBatch::query()
            ->whereRaw('(quantity - quantity_sold - COALESCE(quantity_returned, 0)) > 0')
            ->get(['quantity', 'quantity_sold', 'quantity_returned', 'cost_per_unit', 'expiry_date'])
            ->each(function ($batch) use (&$summary, $today, $cutoff) {
                $value = ((float) $batch->quantity - (float) $batch->quantity_sold - (float) ($batch->quantity_returned ?? 0)) * (float) $batch->cost_per_unit;

                if ($batch->expiry_date === null) {
                    $summary['active_value'] += $value;
                } elseif ($batch->expiry_date->lte($today)) {
                    $summary['expired_value'] += $value;
                } elseif ($batch->expiry_date->lte($cutoff)) {
                    $summary['expiring_soon_value'] += $value;
                    $summary['active_value'] += $value;
                } else {
                    $summary['active_value'] += $value;
                }
            });

        return response()->json([
            ...$batches->toArray(),
            'summary' => [
                'active_value' => round($summary['active_value'], 2),
                'expiring_soon_value' => round($summary['expiring_soon_value'], 2),
                'expired_value' => round($summary['expired_value'], 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $productId = $request->input('product_id');

        $validated = $request->validate([
            'product_id' => [
                'required',
                Rule::exists('products', 'id')
                    ->where(fn ($query) => $query->where('business_id', $businessId)->whereNull('deleted_at')),
            ],
            'batch_number' => [
                'required',
                'string',
                'min:1',
                'max:100',
                Rule::unique('product_batches', 'batch_number')
                    ->where(fn ($query) => $query->where('business_id', $businessId)->where('product_id', $productId)),
            ],
            'source_type' => 'nullable|string|in:opening_stock,stock_count_finding,manual_entry',
            'quantity' => ['required', 'numeric', 'min:0.000001', ProductQuantity::forProduct((string) ($productId ?? ''), 2)],
            'expiry_date' => 'nullable|date',
            'manufacturing_date' => 'nullable|date',
            'total_cost' => 'required|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where('business_id', $businessId),
            ],
            'received_date' => 'nullable|date',
            'storage_location' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'payment_method' => 'nullable|string|in:cash,bank,credit',
        ]);

        $this->assertDatesAreConsistent($validated);

        $validated['business_id'] = $businessId;
        $validated['source_type'] = $validated['source_type'] ?? 'manual_entry';
        $validated['quantity'] = round((float) $validated['quantity'], 2);
        $validated['cost_per_unit'] = $validated['quantity'] > 0
            ? round(((float) $validated['total_cost']) / $validated['quantity'], 2)
            : 0;

        return DB::transaction(function () use ($request, $validated, $businessId) {
            $batch = ProductBatch::create($validated);

            $batch->load('product');
            $this->refreshProductMetrics($batch->product);

            if ((float) $batch->total_cost > 0) {
                app(AccountingService::class)->postGoodsReceiptEntry(
                    $businessId,
                    (float) $batch->total_cost,
                    $batch->id,
                    $request->user()->id,
                    'Goods received - batch '.$batch->batch_number,
                    [
                        'batch_id' => $batch->id,
                        'product_id' => $batch->product_id,
                        'quantity' => (float) $batch->quantity,
                        'total_cost' => (float) $batch->total_cost,
                    ],
                    $validated['payment_method'] ?? 'credit'
                );
            }

            // Real-time reconciliation: force the 1030 inventory asset balance
            // onto the freshly-calculated on-hand stock value inside the same
            // transaction, so a manual batch add can never leave the ledger
            // silently out of sync.
            app(InventorySyncService::class)->sync($businessId, $request->user()->id);

            return response()->json($batch, 201);
        });
    }

    public function grnReceive(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'payment_method' => 'nullable|string|in:cash,bank,credit',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'items.*.batch_number' => ['required', 'string', Rule::unique('product_batches', 'batch_number')->where(fn ($query) => $query->where('business_id', $businessId))],
            'items.*.expiry_date' => 'nullable|date',
            'items.*.manufacturing_date' => 'nullable|date',
            'items.*.storage_location' => 'nullable|string|max:255',
            'items.*.total_cost' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated, $businessId) {
            $po = ! empty($validated['purchase_order_id'])
                ? PurchaseOrder::findOrFail($validated['purchase_order_id'])
                : null;

            if ($po && ! in_array($po->status, ['ordered', 'partially_received'], true)) {
                throw new InsufficientStockException(
                    "Cannot receive purchase order {$po->order_number}: only 'ordered' or 'partially received' orders can be received (currently '{$po->status}')."
                );
            }

            $supplierId = $validated['supplier_id'] ?? $po?->supplier_id;
            $poItems = $po ? $po->items()->get() : collect();

            $totalCost = 0;
            $createdBatchIds = [];
            $updatedProducts = [];

            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $baseQty = round((float) $item['quantity'], 2);
                $unitCost = isset($item['total_cost'])
                    ? round((float) $item['total_cost'] / max($baseQty, 0.0001), 4)
                    : (float) ($product->cost ?? 0);
                $itemTotal = round($baseQty * $unitCost, 2);

                if ($po) {
                    $poItem = $poItems->firstWhere('product_id', (int) $item['product_id']);

                    if (! $poItem) {
                        throw new InsufficientStockException(
                            "Product {$product->name} is not on purchase order {$po->order_number}."
                        );
                    }

                    if ((float) $poItem->quantity > 0
                        && (float) $poItem->received_quantity + $baseQty > (float) $poItem->quantity + 0.001) {
                        throw new InsufficientStockException(
                            "Cannot receive more than the ordered quantity for {$poItem->name}. Ordered: {$poItem->quantity}."
                        );
                    }

                    $poItem->increment('received_quantity', $baseQty);
                }

                if ($product->has_batch) {
                    if (empty($item['expiry_date'])) {
                        throw new InsufficientStockException(
                            "Expiry date is required for batch-managed product {$product->name}."
                        );
                    }

                    $batch = ProductBatch::create([
                        'business_id' => $businessId,
                        'product_id' => $product->id,
                        'batch_number' => $item['batch_number'],
                        'source_type' => 'goods_receipt',
                        'supplier_id' => $supplierId,
                        'quantity' => $baseQty,
                        'received_date' => now()->toDateString(),
                        'expiry_date' => $item['expiry_date'],
                        'manufacturing_date' => $item['manufacturing_date'] ?? null,
                        'storage_location' => $item['storage_location'] ?? null,
                        'cost_per_unit' => round($unitCost, 2),
                        'total_cost' => $itemTotal,
                        'is_active' => true,
                        'metadata' => $po
                            ? ['purchase_order_id' => $po->id]
                            : ['direct' => true],
                    ]);
                    $createdBatchIds[] = $batch->id;
                    $updatedProducts[] = $product->id;
                } else {
                    $oldStock = (float) $product->stock_quantity;
                    $oldCost = (float) $product->cost;
                    $blended = $oldStock > 0
                        ? (($oldStock * $oldCost) + ($baseQty * $unitCost)) / ($oldStock + $baseQty)
                        : $unitCost;
                    $product->increment('stock_quantity', $baseQty);
                    $product->update(['cost' => round($blended, 2)]);
                    $product->autoPrice();
                }

                $totalCost += $itemTotal;
            }

            if ($po) {
                $fullyReceived = $poItems->filter(fn ($poItem) => (float) $poItem->received_quantity < (float) $poItem->quantity)->isEmpty();
                $po->update(array_filter([
                    'status' => $fullyReceived ? 'received' : 'partially_received',
                    'received_at' => $fullyReceived ? now() : null,
                ]));
            }

            foreach (array_unique($updatedProducts) as $productId) {
                $product = Product::find($productId);
                if ($product) {
                    $product->refreshMetrics();
                }
            }

            if ($totalCost > 0 && ! empty($createdBatchIds)) {
                app(AccountingService::class)->postGoodsReceiptEntry(
                    $businessId,
                    $totalCost,
                    $createdBatchIds[0],
                    $request->user()->id,
                    $po ? 'Goods received - '.$po->order_number : 'Goods received',
                    array_filter([
                        'purchase_order_id' => $po?->id,
                        'batch_ids' => $createdBatchIds,
                    ]),
                    'credit'
                );
            }

            if (in_array($validated['payment_method'] ?? null, ['cash', 'bank'], true)) {
                $payment = PurchaseOrderPayment::create([
                    'business_id' => $businessId,
                    'user_id' => $request->user()->id,
                    'purchase_order_id' => $po?->id,
                    'supplier_id' => $supplierId,
                    'payment_number' => 'POPAY-'.strtoupper(Str::random(8)),
                    'amount' => $totalCost,
                    'method' => $validated['payment_method'],
                    'reference_number' => $po ? 'GRN-'.$po->order_number : null,
                    'status' => 'completed',
                    'metadata' => ['source' => 'grn_receive'],
                ]);

                $payment->load(['purchaseOrder:id,order_number', 'supplier:id,name', 'user:id,name']);

                app(AccountingService::class)->postSupplierPaymentEntry($businessId, $payment, $request->user()->id);
            }

            app(InventorySyncService::class)
                ->queueReconcile($businessId, $request->user()->id);

            return response()->json([
                'message' => 'Goods received successfully.',
                'batches' => ProductBatch::withoutGlobalScopes()
                    ->where('business_id', $businessId)
                    ->whereIn('id', $createdBatchIds)
                    ->get(),
            ], 201);
        });
    }

    public function show(ProductBatch $productBatch): JsonResponse
    {
        $productBatch->load('product:id,name,sku,unit', 'supplier:id,name');

        return response()->json($productBatch);
    }

    public function update(Request $request, ProductBatch $productBatch): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'product_id' => [
                'sometimes',
                Rule::exists('products', 'id')
                    ->where(fn ($query) => $query->where('business_id', $businessId)->whereNull('deleted_at')),
            ],
            'batch_number' => [
                'sometimes',
                'string',
                'min:1',
                'max:100',
                Rule::unique('product_batches', 'batch_number')
                    ->where(fn ($query) => $query->where('business_id', $businessId)->where('product_id', $request->input('product_id') ?? $productBatch->product_id))
                    ->ignore($productBatch->id),
            ],
            'quantity' => ['sometimes', 'numeric', 'min:0', ProductQuantity::forProduct((string) ($request->input('product_id') ?? $productBatch->product_id ?? ''), 2)],
            'expiry_date' => 'sometimes|nullable|date',
            'manufacturing_date' => 'nullable|date',
            'total_cost' => 'sometimes|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where('business_id', $businessId),
            ],
            'received_date' => 'nullable|date',
            'storage_location' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        $this->assertDatesAreConsistent($validated, $productBatch);

        if (isset($validated['batch_number'])) {
            $validated['batch_number'] = trim($validated['batch_number']);
        }

        if (isset($validated['total_cost'])) {
            $qty = $validated['quantity'] ?? $productBatch->quantity;
            $validated['cost_per_unit'] = $qty > 0
                ? round($validated['total_cost'] / $qty, 2)
                : 0;
        }

        if (isset($validated['quantity'])) {
            $newQuantity = (float) $validated['quantity'];
            $committed = (float) $productBatch->quantity_sold + (float) ($productBatch->quantity_returned ?? 0);
            if ($newQuantity < $committed - 0.001) {
                throw new InsufficientStockException(
                    "Cannot reduce batch quantity below the already committed amount ({$committed}) for batch {$productBatch->batch_number}."
                );
            }
        }

        $oldOnHandValue = $this->batchOnHandValue($productBatch);

        return DB::transaction(function () use ($request, $validated, $productBatch, $businessId, $oldOnHandValue) {
            $productBatch->update($validated);

            $productBatch->load('product');
            $this->refreshProductMetrics($productBatch->product);

            $delta = round($this->batchOnHandValue($productBatch) - $oldOnHandValue, 2);

            if (abs($delta) > 0.005) {
                $this->postManualBatchAdjustment($businessId, $productBatch, $delta, $request->user()->id);
            }

            // Real-time reconciliation: force the 1030 inventory asset balance
            // onto the freshly-calculated on-hand stock value, so a manual
            // batch edit/deletion can never leave the ledger silently out of
            // sync.
            app(InventorySyncService::class)->sync($businessId, $request->user()->id);

            return response()->json($productBatch);
        });
    }

    public function destroy(ProductBatch $productBatch): JsonResponse
    {
        $product = $productBatch->product;

        $remaining = (float) $productBatch->quantity
            - (float) $productBatch->quantity_sold
            - (float) ($productBatch->quantity_returned ?? 0);

        if ($remaining > 0.001) {
            throw new InsufficientStockException(
                'Cannot delete a batch with remaining stock. Please adjust the stock to zero before deleting.'
            );
        }

        $productBatch->delete();

        $this->refreshProductMetrics($product);

        app(InventorySyncService::class)->sync($productBatch->business_id);

        return response()->json(['message' => 'Product batch deleted.']);
    }

    public function sell(Request $request, ProductBatch $productBatch): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forProduct((string) ($productBatch->product_id ?? ''))],
        ]);

        if ($productBatch->expiry_date && $productBatch->expiry_date->isPast()) {
            return response()->json(['message' => 'Cannot sell from an expired batch.'], 422);
        }

        $remaining = (float) $productBatch->quantity - (float) $productBatch->quantity_sold - (float) $productBatch->quantity_returned;

        if ((float) $validated['quantity'] > $remaining + 0.001) {
            throw new InsufficientStockException("Insufficient batch stock. Available: {$remaining}");
        }

        return DB::transaction(function () use ($request, $productBatch, $validated) {
            $businessId = $request->user()->business_id;

            $productBatch->increment('quantity_sold', $validated['quantity']);

            $this->refreshProductMetrics($productBatch->product);

            $unitCost = (float) ($productBatch->cost_per_unit ?? 0);
            if ($unitCost <= 0 && $productBatch->product) {
                $unitCost = (float) $productBatch->product->cost;
            }
            $amount = round((float) $validated['quantity'] * $unitCost, 2);

            if ($amount > 0) {
                $movement = StockMovement::create([
                    'business_id' => $businessId,
                    'product_id' => $productBatch->product_id,
                    'batch_id' => $productBatch->id,
                    'quantity' => $validated['quantity'],
                    'type' => 'reduction',
                    'reference_type' => 'batch_sale',
                    'reference_id' => $productBatch->id,
                    'notes' => 'Batch sale - '.$productBatch->batch_number,
                ]);

                app(AccountingService::class)->post($businessId, [
                    'date' => now()->toDateString(),
                    'description' => 'Batch sale - '.$productBatch->batch_number,
                    'reference_type' => 'batch_sale',
                    'reference_id' => $movement->id,
                    'user_id' => $request->user()->id,
                    'metadata' => [
                        'batch_id' => $productBatch->id,
                        'product_id' => $productBatch->product_id,
                        'quantity' => (float) $validated['quantity'],
                        'unit_cost' => $unitCost,
                    ],
                ], [
                    ['code' => '5010', 'debit' => $amount, 'description' => 'COGS - '.$productBatch->batch_number],
                    ['code' => '1030', 'credit' => $amount, 'description' => 'COGS - '.$productBatch->batch_number],
                ]);
            }

            return response()->json($productBatch);
        });
    }

    public function sellFefo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forProduct((string) $request->input('product_id', ''))],
        ]);

        $productId = (int) $validated['product_id'];
        $quantityNeeded = (float) $validated['quantity'];
        $businessId = $request->user()->business_id;

        return DB::transaction(function () use ($request, $productId, $quantityNeeded, $businessId) {
            $product = Product::findOrFail($productId);
            $deductions = StockService::deductForSale($product, $quantityNeeded, $businessId);

            $cogs = 0.0;
            foreach ($deductions as $deduction) {
                $cogs += (float) ($deduction['unit_cost'] ?? 0) * (float) $deduction['quantity'];
            }
            $cogs = round($cogs, 2);

            if ($cogs > 0) {
                $movements = StockMovement::recordDeductions([
                    'business_id' => $businessId,
                    'product_id' => $productId,
                    'quantity' => $quantityNeeded,
                    'type' => 'reduction',
                    'reference_type' => 'fefo_sale',
                    'reference_id' => $productId,
                    'notes' => 'FEFO sale',
                ], $deductions);

                app(AccountingService::class)->post($businessId, [
                    'date' => now()->toDateString(),
                    'description' => 'FEFO sale',
                    'reference_type' => 'fefo_sale',
                    'reference_id' => $movements[0]->id,
                    'user_id' => $request->user()->id,
                    'metadata' => [
                        'product_id' => $productId,
                        'quantity' => $quantityNeeded,
                        'cogs' => $cogs,
                        'deductions' => $deductions,
                    ],
                ], [
                    ['code' => '5010', 'debit' => $cogs, 'description' => 'COGS - FEFO sale'],
                    ['code' => '1030', 'credit' => $cogs, 'description' => 'COGS - FEFO sale'],
                ]);
            }

            return response()->json([
                'product_id' => $productId,
                'total_sold' => $quantityNeeded,
                'batches' => $deductions,
                'cogs' => $cogs,
            ]);
        });
    }

    public function adjust(Request $request, ProductBatch $productBatch): JsonResponse
    {
        $validated = $request->validate([
            'quantity_adjusted' => ['required', 'numeric', ProductQuantity::forProduct((string) ($productBatch->product_id ?? ''))],
            'reason' => 'nullable|string',
        ]);

        $adjustment = (float) $validated['quantity_adjusted'];

        if ($adjustment < 0) {
            $absAdjustment = abs($adjustment);
            $remaining = (float) $productBatch->quantity - (float) $productBatch->quantity_sold - (float) ($productBatch->quantity_returned ?? 0);
            if ($absAdjustment > $remaining + 0.001) {
                throw new InsufficientStockException(
                    "Cannot adjust more than available quantity ({$remaining}) for batch {$productBatch->batch_number}."
                );
            }
        }

        return DB::transaction(function () use ($request, $productBatch, $validated, $adjustment) {
            $businessId = $request->user()->business_id;
            $quantityBefore = (float) $productBatch->quantity;

            if ($adjustment > 0) {
                $productBatch->increment('quantity', $adjustment);
            } else {
                $productBatch->decrement('quantity', abs($adjustment));
            }

            $unitCost = (float) ($productBatch->cost_per_unit ?? 0);
            if ($unitCost <= 0 && $productBatch->product) {
                $unitCost = (float) $productBatch->product->cost;
            }

            $adjustmentRecord = InventoryAdjustment::create([
                'business_id' => $businessId,
                'user_id' => $request->user()->id,
                'product_id' => $productBatch->product_id,
                'batch_id' => $productBatch->id,
                'adjustment_number' => 'ADJ-'.strtoupper(Str::random(8)),
                'type' => $adjustment >= 0 ? 'count_surplus' : 'count_deficit',
                'quantity_before' => $quantityBefore,
                'quantity_adjusted' => $adjustment,
                'quantity_after' => max(0, $quantityBefore + $adjustment),
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'reason' => $validated['reason'] ?? null,
                'notes' => $validated['reason'] ?? null,
                'status' => 'completed',
            ]);

            $productBatch->load('product');
            $this->refreshProductMetrics($productBatch->product);

            $amount = round(abs($adjustment) * $unitCost, 2);
            if ($amount > 0) {
                app(AccountingService::class)->postInventoryAdjustmentEntry(
                    $businessId,
                    $adjustmentRecord,
                    $amount,
                    $request->user()->id
                );
            }

            return response()->json($productBatch);
        });
    }

    public function expiryAlerts(Request $request): JsonResponse
    {
        $days = $request->integer('days', 30);

        $expiringSoon = ProductBatch::where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>=', now())
            ->where('is_active', true)
            ->with('product:id,name,sku,unit')
            ->orderBy('expiry_date', 'asc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($expiringSoon);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $sensitivity = ($business->mergedSettings()['low_stock_sensitivity'] ?? 'normal');
        $threshold = $sensitivity === 'strict' ? DB::raw('min_stock * 1.5') : null;

        $query = Product::where('is_active', true)->where('min_stock', '>', 0);
        if ($threshold) {
            $query->where('stock_quantity', '<=', $threshold);
        } else {
            $query->whereColumn('stock_quantity', '<=', 'min_stock');
        }

        $products = $query->orderBy('stock_quantity', 'asc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($products);
    }

    /**
     * A batch cannot expire before it was manufactured. The two dates are
     * validated independently by the rules, so the cross-field check lives
     * here and surfaces as a normal 422 on `expiry_date`.
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertDatesAreConsistent(array $validated, ?ProductBatch $existing = null): void
    {
        $expiry = array_key_exists('expiry_date', $validated)
            ? $validated['expiry_date']
            : $existing?->expiry_date;
        $manufacturing = array_key_exists('manufacturing_date', $validated)
            ? $validated['manufacturing_date']
            : $existing?->manufacturing_date;

        if (empty($expiry) || empty($manufacturing)) {
            return;
        }

        if (Carbon::parse($expiry)->startOfDay()->lt(Carbon::parse($manufacturing)->startOfDay())) {
            throw ValidationException::withMessages([
                'expiry_date' => 'The expiry date must be on or after the manufacturing date.',
            ]);
        }
    }

    /**
     * Only batch-managed products derive `stock_quantity` from their batches.
     * Simple products own that column outright (backed by their goods-receipt
     * cost layers), so recomputing it from a stray batch would wipe their
     * on-hand stock.
     */
    private function refreshProductMetrics(?Product $product): void
    {
        if ($product && $product->has_batch) {
            $product->refreshMetrics();
        }
    }

    /**
     * The on-hand cost value of a single batch, matching the exact costing
     * rule InventoryValuationService applies to batch-managed products
     * (remaining quantity times cost per unit, active and non-expired only).
     * Used to measure how a manual quantity / total-cost edit changes the
     * batch's contribution to the 1030 Inventory Asset account.
     */
    private function batchOnHandValue(ProductBatch $batch): float
    {
        if (! $batch->is_active) {
            return 0.0;
        }

        if ($batch->expiry_date && $batch->expiry_date->isPast()) {
            return 0.0;
        }

        return round(
            ((float) $batch->quantity - (float) $batch->quantity_sold)
                * (float) $batch->cost_per_unit,
            2
        );
    }

    /**
     * Post the journal entry that books a manual batch quantity / cost edit:
     * an increase in on-hand value debits 1030 and credits the equity offset
     * (3010), a decrease debits the inventory-adjustment expense (5020) and
     * credits 1030. Balanced by construction; a no-op when the delta is 0.
     */
    private function postManualBatchAdjustment(string $businessId, ProductBatch $batch, float $delta, ?int $userId = null): void
    {
        $amount = abs($delta);
        $label = 'Manual Batch Adjustment - Batch: '.$batch->batch_number;

        if ($delta > 0) {
            $lines = [
                ['code' => '1030', 'debit' => $amount, 'description' => $label],
                ['code' => '3010', 'credit' => $amount, 'description' => $label],
            ];
        } else {
            $lines = [
                ['code' => '5020', 'debit' => $amount, 'description' => $label],
                ['code' => '1030', 'credit' => $amount, 'description' => $label],
            ];
        }

        app(AccountingService::class)->post($businessId, [
            'date' => now()->toDateString(),
            'description' => $label,
            'reference_type' => 'manual_batch_adjustment',
            'reference_id' => $batch->id,
            'user_id' => $userId,
            'metadata' => [
                'batch_id' => $batch->id,
                'product_id' => $batch->product_id,
                'delta' => $delta,
                'quantity' => (float) $batch->quantity,
                'cost_per_unit' => (float) $batch->cost_per_unit,
            ],
        ], $lines);
    }
}
