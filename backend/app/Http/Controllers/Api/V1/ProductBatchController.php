<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\StockMovement;
use App\Rules\ProductQuantity;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductBatch::query()->with([
            'product:id,name,sku,unit',
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

        $riskCutoff = now()->addDays((int) ($request->user()->business->mergedSettings()['expiry_warning_days'] ?? 30));
        $valueAtRisk = ProductBatch::query()
            ->where('expiry_date', '<=', $riskCutoff)
            ->whereRaw('(quantity - quantity_sold - COALESCE(quantity_returned, 0)) > 0')
            ->with('product:id,price')
            ->get()
            ->sum(fn ($b) => ((float) $b->quantity - (float) $b->quantity_sold - (float) ($b->quantity_returned ?? 0)) * (float) ($b->product?->price ?? 0));

        return response()->json([
            ...$batches->toArray(),
            'value_at_risk' => round($valueAtRisk, 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $productId = $request->input('product_id');
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'batch_number' => [
                'required',
                'string',
                Rule::unique('product_batches', 'batch_number')
                    ->where(fn ($query) => $query->where('business_id', $businessId)->where('product_id', $productId)),
            ],
            'source_type' => 'nullable|string|in:opening_stock,stock_count_finding,manual_entry',
            'quantity' => ['required', 'numeric', 'min:0', ProductQuantity::forProduct((string) ($productId ?? ''))],
            'expiry_date' => 'nullable|date',
            'manufacturing_date' => 'nullable|date',
            'total_cost' => 'required|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'received_date' => 'nullable|date',
            'storage_location' => 'nullable|string',
            'metadata' => 'nullable|array',
            'payment_method' => 'nullable|string|in:cash,bank,credit',
        ]);

        $validated['business_id'] = $businessId;

        $validated['source_type'] = $validated['source_type'] ?? 'manual_entry';

        $validated['cost_per_unit'] = $validated['quantity'] > 0
            ? round($validated['total_cost'] / $validated['quantity'], 2)
            : 0;

        $batch = ProductBatch::create($validated);

        $batch->load('product');
        $product = $batch->product;
        $product?->recalculateStockQuantity();
        $product?->updateWeightedAverageCost();
        $product?->autoPrice();

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

        return response()->json($batch, 201);
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
            'product_id' => 'sometimes|exists:products,id',
            'batch_number' => [
                'sometimes',
                'string',
                Rule::unique('product_batches', 'batch_number')
                    ->where(fn ($query) => $query->where('business_id', $businessId)->where('product_id', $request->input('product_id') ?? $productBatch->product_id))
                    ->ignore($productBatch->id),
            ],
            'quantity' => ['sometimes', 'numeric', 'min:0', ProductQuantity::forProduct((string) ($request->input('product_id') ?? $productBatch->product_id ?? ''))],
            'expiry_date' => 'sometimes|date',
            'manufacturing_date' => 'nullable|date',
            'total_cost' => 'sometimes|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'received_date' => 'nullable|date',
            'storage_location' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

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

        $productBatch->update($validated);

        $productBatch->load('product');
        $product = $productBatch->product;
        $product?->recalculateStockQuantity();
        $product?->updateWeightedAverageCost();
        $product?->autoPrice();

        return response()->json($productBatch);
    }

    public function destroy(ProductBatch $productBatch): JsonResponse
    {
        $product = $productBatch->product;

        $remaining = (float) $productBatch->quantity
            - (float) $productBatch->quantity_sold
            - (float) ($productBatch->quantity_returned ?? 0);

        if ($remaining > 0.001) {
            throw new InsufficientStockException(
                "Cannot delete batch {$productBatch->batch_number}: it still has {$remaining} units of sellable stock. Adjust the batch to zero before deleting."
            );
        }

        $productBatch->delete();

        $product?->recalculateStockQuantity();
        $product?->updateWeightedAverageCost();
        $product?->autoPrice();

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

            if ($productBatch->product) {
                $productBatch->product->recalculateStockQuantity();
                $productBatch->product->updateWeightedAverageCost();
                $productBatch->product->autoPrice();
            }

            $unitCost = (float) ($productBatch->cost_per_unit ?? 0);
            if ($unitCost <= 0 && $productBatch->product) {
                $unitCost = (float) $productBatch->product->cost;
            }
            $amount = round((float) $validated['quantity'] * $unitCost, 2);

            if ($amount > 0) {
                $movement = StockMovement::create([
                    'business_id' => $businessId,
                    'product_id' => $productBatch->product_id,
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
            $deductions = ProductBatch::deductFefo($productId, $businessId, $quantityNeeded);

            $product = Product::find($productId);
            if ($product) {
                $product->recalculateStockQuantity();
                $product->updateWeightedAverageCost();
                $product->autoPrice();
            }

            $cogs = 0.0;
            foreach ($deductions as $deduction) {
                $cogs += (float) ($deduction['unit_cost'] ?? 0) * (float) $deduction['quantity'];
            }
            $cogs = round($cogs, 2);

            if ($cogs > 0) {
                $movement = StockMovement::create([
                    'business_id' => $businessId,
                    'product_id' => $productId,
                    'quantity' => $quantityNeeded,
                    'type' => 'reduction',
                    'reference_type' => 'fefo_sale',
                    'reference_id' => $productId,
                    'notes' => 'FEFO sale',
                ]);

                app(AccountingService::class)->post($businessId, [
                    'date' => now()->toDateString(),
                    'description' => 'FEFO sale',
                    'reference_type' => 'fefo_sale',
                    'reference_id' => $movement->id,
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
            $p = $productBatch->product;
            $p?->recalculateStockQuantity();
            $p?->updateWeightedAverageCost();
            $p?->autoPrice();

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

    public function grnReceive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'items.*.batch_number' => 'required|string',
            'items.*.expiry_date' => 'required|date|after:today',
            'items.*.total_cost' => 'required|numeric|min:0',
            'items.*.selling_price' => 'nullable|numeric|min:0',
            'items.*.manufacturing_date' => 'nullable|date',
            'items.*.storage_location' => 'nullable|string',
            'payment_method' => 'nullable|string|in:cash,bank,credit',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $businessId = $request->user()->business_id;
            $createdBatches = [];

            $po = null;
            if (! empty($validated['purchase_order_id'])) {
                $po = PurchaseOrder::find($validated['purchase_order_id']);
                if (! $po) {
                    throw new InsufficientStockException('Purchase order not found.');
                }

                if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
                    throw new InsufficientStockException(
                        "Cannot receive purchase order {$po->order_number}: only 'ordered' or 'partially received' orders can be received (currently '{$po->status}')."
                    );
                }
            }

            $updatedProductIds = [];

            foreach ($validated['items'] as $item) {
                $unitCost = $item['quantity'] > 0
                    ? round($item['total_cost'] / $item['quantity'], 2)
                    : 0;

                $batch = ProductBatch::create([
                    'business_id' => $businessId,
                    'product_id' => $item['product_id'],
                    'batch_number' => $item['batch_number'],
                    'source_type' => 'goods_receipt',
                    'quantity' => $item['quantity'],
                    'expiry_date' => $item['expiry_date'],
                    'cost_per_unit' => $unitCost,
                    'total_cost' => $item['total_cost'],
                    'selling_price' => $item['selling_price'] ?? null,
                    'manufacturing_date' => $item['manufacturing_date'] ?? null,
                    'storage_location' => $item['storage_location'] ?? null,
                    'supplier_id' => $validated['supplier_id'] ?? $po?->supplier_id ?? null,
                    'received_date' => now()->toDateString(),
                    'is_active' => true,
                ]);

                $product = Product::find($item['product_id']);
                if ($product && $product->has_batch) {
                    $updatedProductIds[] = $product->id;
                }

                $createdBatches[] = $batch;
            }

            foreach (array_unique($updatedProductIds) as $pid) {
                $p = Product::find($pid);
                $p?->recalculateStockQuantity();
                $p?->updateWeightedAverageCost();
                $p?->autoPrice();
            }

            if (! empty($validated['purchase_order_id'])) {
                foreach ($validated['items'] as $item) {
                    $poItem = PurchaseOrderItem::where('purchase_order_id', $po->id)
                        ->where('product_id', $item['product_id'])
                        ->first();
                    if ($poItem) {
                        if ((float) $poItem->quantity > 0
                            && (float) $poItem->received_quantity + (float) $item['quantity'] > (float) $poItem->quantity + 0.001) {
                            throw new InsufficientStockException(
                                "Cannot receive more than the ordered quantity for {$poItem->name}. Ordered: {$poItem->quantity}."
                            );
                        }
                        $poItem->increment('received_quantity', $item['quantity']);
                    }
                }

                $allFullyReceived = $po->items()->whereColumn('received_quantity', '<', 'quantity')->doesntExist();
                $statusUpdate = ['status' => $allFullyReceived ? 'received' : 'partially_received'];
                if ($allFullyReceived) {
                    $statusUpdate['received_at'] = now();
                }
                $po->update($statusUpdate);

                $totalCost = array_sum(array_map(fn ($i) => (float) $i['total_cost'], $validated['items']));

                $paymentMethod = $validated['payment_method'] ?? 'credit';
                if (in_array($paymentMethod, ['cash', 'bank'], true) && $totalCost > 0.005) {
                    $directPayment = PurchaseOrderPayment::create([
                        'business_id' => $businessId,
                        'user_id' => $request->user()->id,
                        'purchase_order_id' => $po->id,
                        'supplier_id' => $validated['supplier_id'] ?? $po->supplier_id,
                        'payment_number' => 'POPAY-'.strtoupper(Str::random(8)),
                        'amount' => round($totalCost, 2),
                        'method' => $paymentMethod,
                        'status' => 'completed',
                        'notes' => 'Paid directly on goods receipt',
                        'metadata' => ['source' => 'goods_receipt_direct'],
                    ]);

                    app(AccountingService::class)->postSupplierPaymentEntry($businessId, $directPayment, $request->user()->id);
                }

                app(AccountingService::class)->postGoodsReceiptEntry(
                    $businessId,
                    $totalCost,
                    (int) ($createdBatches[0]->id ?? 0),
                    $request->user()->id,
                    'Goods received - batch GRN',
                    [
                        'purchase_order_id' => $po->id,
                        'batch_ids' => collect($createdBatches)->pluck('id')->all(),
                    ]
                );
            } else {
                $paymentMethod = $validated['payment_method'] ?? 'credit';
                foreach ($createdBatches as $batch) {
                    if ((float) $batch->total_cost <= 0) {
                        continue;
                    }
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
                        $paymentMethod
                    );
                }
            }

            return response()->json([
                'message' => 'Goods received successfully.',
                'batches' => $createdBatches,
                'count' => count($createdBatches),
            ], 201);
        });
    }
}
