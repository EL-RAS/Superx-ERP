<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Rules\ProductQuantity;
use App\Scopes\BusinessScope;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InventoryAdjustmentController extends Controller
{
    private const DEDUCTION_TYPES = ['waste', 'damage', 'count_deficit', 'purchase_return'];

    private const ADDITION_TYPES = ['received', 'count_surplus'];

    private const RETURN_TYPES = ['return'];

    public function index(Request $request): JsonResponse
    {
        $query = InventoryAdjustment::query()->with([
            'product:id,name,sku,unit',
            'batch:id,batch_number,supplier_id',
            'batch.supplier:id,name',
            'supplier:id,name',
            'user:id,name',
        ]);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('adjustment_number', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%")
                    ->orWhereHas('product', function ($pq) use ($search) {
                        $pq->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->has('type') && $request->input('type') !== 'all') {
            $type = $request->input('type');
            if ($type === 'count') {
                $query->whereIn('type', ['count_deficit', 'count_surplus']);
            } else {
                $query->where('type', $type);
            }
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->input('batch_id'));
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $adjustments = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($adjustments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'type' => 'required|string|in:waste,damage,count_deficit,count_surplus,received,return,purchase_return',
            'quantity' => ['required', 'numeric', 'min:0', ProductQuantity::forProduct((string) $request->input('product_id', ''))],
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'reason' => 'nullable|string',
            'invoice_id' => 'nullable|exists:invoices,id',
            'selling_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|string|in:cash,bank,credit',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'responsibility' => 'nullable|string|in:store,supplier',
            'liability_type' => 'nullable|string|in:internal_store_loss,supplier_claim',
            'generate_purchase_return' => 'nullable|boolean',
            'purchase_return_id' => 'nullable|exists:inventory_adjustments,id',
        ]);

        $type = $validated['type'];
        $quantity = (float) $validated['quantity'];

        if ($type === 'return' && empty($validated['batch_id'])) {
            throw new InsufficientStockException('A batch must be specified to restock returned goods.');
        }

        if ($type === 'purchase_return' && empty($validated['supplier_id'])) {
            throw new InsufficientStockException('A supplier must be specified for a purchase return.');
        }

        $responsibility = $validated['responsibility'] ?? ($type === 'purchase_return' ? 'supplier' : 'store');

        $liabilityType = $validated['liability_type']
            ?? (in_array($type, ['waste', 'damage'], true) && $responsibility === 'supplier'
                ? 'supplier_claim'
                : ($type === 'purchase_return' ? 'supplier_claim' : 'internal_store_loss'));

        $isSupplierLiability = $liabilityType === 'supplier_claim';

        if ($isSupplierLiability && empty($validated['supplier_id'])) {
            throw new InsufficientStockException(
                'A supplier must be specified when the supplier is liable for the '.$type.'.'
            );
        }

        $isDeduction = in_array($type, self::DEDUCTION_TYPES, true);
        $isReturn = in_array($type, self::RETURN_TYPES, true);
        $quantityAdjusted = $isDeduction ? -$quantity : $quantity;

        return DB::transaction(function () use ($request, $validated, $type, $quantity, $quantityAdjusted, $isDeduction, $isReturn, $responsibility, $liabilityType, $isSupplierLiability) {
            $businessId = $request->user()->business_id;
            $product = Product::findOrFail($validated['product_id']);
            $batch = null;

            if (! empty($validated['batch_id'])) {
                $batch = ProductBatch::findOrFail($validated['batch_id']);
            }

            $quantityBefore = (float) ($product->stock_quantity ?? 0);

            if ($isDeduction && $batch) {
                $remaining = (float) $batch->quantity - (float) $batch->quantity_sold - (float) ($batch->quantity_returned ?? 0);
                if ($quantity > $remaining + 0.001) {
                    throw new InsufficientStockException(
                        "Insufficient batch stock. Batch {$batch->batch_number} has {$remaining} available, {$quantity} requested."
                    );
                }
            }

            if ($isReturn && $batch) {
                $soldFromBatch = (float) $batch->quantity_sold;
                if ($quantity > $soldFromBatch + 0.001) {
                    throw new InsufficientStockException(
                        "Cannot return more than the quantity sold from batch {$batch->batch_number} ({$soldFromBatch})."
                    );
                }
            }

            $quantityAfter = max(0, $quantityBefore + $quantityAdjusted);

            $adjustmentNumber = 'ADJ-'.strtoupper(Str::random(8));

            $notes = $validated['notes'] ?? $validated['reason'] ?? null;

            $adjustment = InventoryAdjustment::create([
                'business_id' => $businessId,
                'user_id' => $request->user()->id,
                'product_id' => $validated['product_id'],
                'batch_id' => $validated['batch_id'] ?? null,
                'adjustment_number' => $adjustmentNumber,
                'type' => $type,
                'liability_type' => $liabilityType,
                'supplier_id' => $isSupplierLiability ? ($validated['supplier_id'] ?? null) : null,
                'purchase_return_id' => $validated['purchase_return_id'] ?? null,
                'quantity_before' => $quantityBefore,
                'quantity_adjusted' => $quantityAdjusted,
                'quantity_after' => $quantityAfter,
                'unit_cost' => $validated['unit_cost'] ?? null,
                'reason' => $notes,
                'notes' => $notes,
                'status' => 'completed',
                'metadata' => match (true) {
                    $isReturn => [
                        'invoice_id' => $validated['invoice_id'] ?? null,
                        'refund_method' => $validated['refund_method'] ?? 'credit',
                        'selling_price' => $validated['selling_price'] ?? null,
                        'tax_rate' => $validated['tax_rate'] ?? null,
                    ],
                    $type === 'purchase_return' => [
                        'supplier_id' => $validated['supplier_id'] ?? null,
                        'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                        'responsibility' => 'supplier',
                        'debit_note' => true,
                    ],
                    $responsibility === 'supplier' => [
                        'supplier_id' => $validated['supplier_id'] ?? null,
                        'responsibility' => 'supplier',
                        'debit_note' => true,
                    ],
                    default => ['responsibility' => 'store'],
                },
            ]);

            if ($batch) {
                if ($isDeduction) {
                    $batch->increment('quantity_sold', $quantity);
                } elseif ($isReturn) {
                    $batch->decrement('quantity_sold', $quantity);
                } else {
                    $batch->increment('quantity', $quantity);
                }
            } elseif (! $product->has_batch) {
                $product->increment('stock_quantity', $quantityAdjusted);
            }

            if ($batch || $product->has_batch) {
                $product->recalculateStockQuantity();
                $product->updateWeightedAverageCost();
            }

            $unitCost = (float) ($validated['unit_cost'] ?? 0);
            if ($unitCost <= 0 && $batch) {
                $unitCost = (float) $batch->cost_per_unit;
            }
            if ($unitCost <= 0) {
                $unitCost = (float) $product->cost;
            }
            app(AccountingService::class)->postInventoryAdjustmentEntry(
                $businessId,
                $adjustment,
                $quantity * $unitCost,
                $request->user()->id
            );

            if ($request->boolean('generate_purchase_return') && $isSupplierLiability && in_array($type, ['waste', 'damage'], true)) {
                $companionNote = ($notes ? $notes.' - ' : '').'Auto-generated debit note for supplier '.$type.' claim '.$adjustment->adjustment_number;

                $companion = InventoryAdjustment::create([
                    'business_id' => $businessId,
                    'user_id' => $request->user()->id,
                    'product_id' => $validated['product_id'],
                    'batch_id' => $validated['batch_id'] ?? null,
                    'adjustment_number' => 'ADJ-'.strtoupper(Str::random(8)),
                    'type' => 'purchase_return',
                    'liability_type' => 'supplier_claim',
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'quantity_before' => $quantityBefore,
                    'quantity_adjusted' => $quantityAdjusted,
                    'quantity_after' => $quantityAfter,
                    'unit_cost' => $unitCost > 0 ? $unitCost : null,
                    'reason' => $companionNote,
                    'notes' => $companionNote,
                    'status' => 'completed',
                    'metadata' => [
                        'supplier_id' => $validated['supplier_id'] ?? null,
                        'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                        'responsibility' => 'supplier',
                        'debit_note' => true,
                        'source_claim_id' => $adjustment->id,
                        'auto_generated' => true,
                    ],
                ]);

                $adjustment->forceFill(['purchase_return_id' => $companion->id])->save();
                $adjustment->refresh();
            }

            if ($isReturn) {
                [$revenue, $tax] = $this->resolveReturnValue($validated, $quantity, $product);

                if ($revenue + $tax > 0) {
                    app(AccountingService::class)->postSalesReturnEntry(
                        $businessId,
                        $adjustment,
                        $revenue,
                        $tax,
                        $request->user()->id,
                        $validated['refund_method'] ?? 'credit'
                    );
                }
            }

            $adjustment->load([
                'product:id,name,sku,unit',
                'batch:id,batch_number',
                'user:id,name',
            ]);

            return response()->json($adjustment, 201);
        });
    }

    /**
     * Resolve the sales value being returned (pre-tax revenue + tax) from the
     * original invoice when one is supplied, otherwise from the explicit
     * selling_price/tax_rate or the product's default selling price.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: float, 1: float}
     */
    private function resolveReturnValue(array $validated, float $quantity, Product $product): array
    {
        if (! empty($validated['invoice_id'])) {
            $invoice = Invoice::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $product->business_id)
                ->with('items')
                ->find((int) $validated['invoice_id']);

            $invoiceItems = $invoice?->items->filter(fn ($item) => (int) $item->product_id === (int) $product->id) ?? collect();
            if ($invoiceItems->isNotEmpty()) {
                $unitPrice = $invoiceItems->avg(fn ($item) => (float) $item->unit_price);
                $taxRate = $invoiceItems->max(fn ($item) => (float) $item->tax_rate);
                $revenue = round($quantity * $unitPrice, 2);
                $tax = round($revenue * ($taxRate / 100), 2);

                return [$revenue, $tax];
            }
        }

        if (isset($validated['selling_price'])) {
            $revenue = round($quantity * (float) $validated['selling_price'], 2);
            $tax = round($revenue * ((float) ($validated['tax_rate'] ?? 0) / 100), 2);

            return [$revenue, $tax];
        }

        $revenue = round($quantity * (float) ($product->price ?? 0), 2);
        $tax = round($revenue * ((float) ($product->tax_rate ?? 0) / 100), 2);

        return [$revenue, $tax];
    }

    public function show(InventoryAdjustment $inventoryAdjustment): JsonResponse
    {
        $inventoryAdjustment->load([
            'product:id,name,sku,unit',
            'batch:id,batch_number,supplier_id',
            'batch.supplier:id,name',
            'supplier:id,name',
            'user:id,name',
            'journalEntries.lines.account:id,code,name',
        ]);

        return response()->json($inventoryAdjustment);
    }

    public function destroy(InventoryAdjustment $inventoryAdjustment): JsonResponse
    {
        return DB::transaction(function () use ($inventoryAdjustment) {
            $businessId = $inventoryAdjustment->business_id;
            $accounting = app(AccountingService::class);

            // Reverse the GL effect of every posted entry tied to this
            // adjustment (the main inventory_adjustment entry and, for returns,
            // the sales_return entry) before the source record disappears.
            foreach ($inventoryAdjustment->journalEntries()->with('lines')->get() as $entry) {
                $accounting->reverseJournalEntry($businessId, $entry);
            }

            // Restore stock to its pre-adjustment position (mirror of store()).
            $quantityAdjusted = (float) $inventoryAdjustment->quantity_adjusted;
            $type = $inventoryAdjustment->type;
            $product = $inventoryAdjustment->product_id
                ? Product::withTrashed()->find($inventoryAdjustment->product_id)
                : null;
            $batch = $inventoryAdjustment->batch_id
                ? ProductBatch::withTrashed()->find($inventoryAdjustment->batch_id)
                : null;

            if ($batch) {
                if (in_array($type, self::DEDUCTION_TYPES, true) || in_array($type, self::RETURN_TYPES, true)) {
                    // store() moved quantity_sold by -quantity_adjusted.
                    $batch->increment('quantity_sold', $quantityAdjusted);
                } else {
                    // received / count_surplus incremented quantity directly.
                    $batch->increment('quantity', -$quantityAdjusted);
                }
                if ($product) {
                    $product->recalculateStockQuantity();
                }
            } elseif ($product && ! $product->has_batch) {
                $product->increment('stock_quantity', -$quantityAdjusted);
            }

            if ($product) {
                $product->updateWeightedAverageCost();
            }

            $inventoryAdjustment->delete();

            return response()->json(['message' => 'Adjustment reversed and deleted.']);
        });
    }

    public function autoWaste(Request $request): JsonResponse
    {
        $now = now();
        $dryRun = $request->boolean('dry_run', false);

        $expiredBatches = ProductBatch::where('expiry_date', '<', $now)
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->with('product')
            ->get();

        if ($expiredBatches->isEmpty()) {
            return response()->json(['message' => 'No expired batches with remaining stock found.', 'processed' => 0]);
        }

        $processed = 0;

        foreach ($expiredBatches as $batch) {
            $remaining = (float) $batch->quantity - (float) $batch->quantity_sold;
            $product = $batch->product;

            if (! $product) {
                continue;
            }

            if ($dryRun) {
                $processed++;

                continue;
            }

            try {
                DB::beginTransaction();

                $adjustmentNumber = 'AUTO-'.strtoupper(Str::random(8));

                $qtyBefore = (float) $product->stock_quantity;

                $adjustment = InventoryAdjustment::create([
                    'business_id' => $product->business_id,
                    'user_id' => $request->user()->id,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'adjustment_number' => $adjustmentNumber,
                    'type' => 'waste',
                    'liability_type' => 'internal_store_loss',
                    'quantity_before' => $qtyBefore,
                    'quantity_adjusted' => -$remaining,
                    'quantity_after' => max(0, $qtyBefore - $remaining),
                    'notes' => "Automated Adjustment: Batch expired on {$batch->expiry_date->format('Y-m-d')}",
                    'reason' => "Automated Adjustment: Batch expired on {$batch->expiry_date->format('Y-m-d')}",
                    'status' => 'completed',
                ]);

                $batch->increment('quantity_sold', $remaining);

                $product->recalculateStockQuantity();
                $product->updateWeightedAverageCost();

                $unitCost = (float) ($batch->cost_per_unit ?? 0);
                if ($unitCost <= 0) {
                    $unitCost = (float) $product->cost;
                }
                app(AccountingService::class)->postInventoryAdjustmentEntry(
                    $product->business_id,
                    $adjustment,
                    $remaining * $unitCost,
                    $request->user()->id
                );

                DB::commit();
                $processed++;
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('Auto-waste failed for batch', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $dryRun
                ? "Dry-run: {$processed} expired batch(es) would be processed."
                : "Auto-waste completed: {$processed} batch(es) processed.",
            'processed' => $processed,
            'dry_run' => $dryRun,
        ]);
    }

    public function expiryAlerts(Request $request): JsonResponse
    {
        $days = $request->integer('days', (int) (($request->user()->business->mergedSettings()['expiry_warning_days'] ?? 30)));

        $batches = ProductBatch::where('is_active', true)
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>=', now()->subDay())
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->with('product:id,name,sku,unit,category')
            ->orderBy('expiry_date', 'asc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($batches);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $now = now()->toDateString();
        $type = $request->input('type', 'all');

        $buildSellable = function () use ($now) {
            return ProductBatch::selectRaw('product_id, COALESCE(SUM(quantity - quantity_sold), 0) AS sellable_stock')
                ->where(function ($q) use ($now) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $now);
                })
                ->whereRaw('(quantity - quantity_sold) > 0')
                ->groupBy('product_id');
        };

        $base = Product::where('products.is_active', true)
            ->where(function ($tracked) {
                $tracked->where('products.has_batch', true)
                    ->orWhere('products.stock_quantity', '>', 0)
                    ->orWhere('products.min_stock', '>', 0);
            })
            ->leftJoinSub($buildSellable(), 'ss', 'products.id', '=', 'ss.product_id')
            ->select('products.*')
            ->selectRaw('COALESCE(ss.sellable_stock, products.stock_quantity) AS effective_stock');

        $base->where(function ($q) {
            $q->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= 0')
                ->orWhere(function ($q2) {
                    $q2->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) > 0')
                        ->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= products.min_stock')
                        ->where('products.min_stock', '>', 0);
                });
        });

        if ($type === 'out') {
            $base->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= 0');
        } elseif ($type === 'low') {
            $base->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) > 0')
                ->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= products.min_stock')
                ->where('products.min_stock', '>', 0);
        }

        $products = $base->orderByRaw('CASE WHEN COALESCE(ss.sellable_stock, products.stock_quantity) <= 0 THEN 0 ELSE 1 END')
            ->orderByRaw('COALESCE(ss.sellable_stock, products.stock_quantity) ASC')
            ->orderBy('products.name', 'asc')
            ->paginate($request->integer('per_page', 10));

        $counterBase = function () use ($buildSellable) {
            return Product::where('products.is_active', true)
                ->where(function ($tracked) {
                    $tracked->where('products.has_batch', true)
                        ->orWhere('products.stock_quantity', '>', 0)
                        ->orWhere('products.min_stock', '>', 0);
                })
                ->leftJoinSub($buildSellable(), 'ss', 'products.id', '=', 'ss.product_id')
                ->select('products.id');
        };

        $outCount = $counterBase()->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= 0')->count();
        $lowCount = $counterBase()
            ->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) > 0')
            ->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= products.min_stock')
            ->where('products.min_stock', '>', 0)
            ->count();

        $reorderCost = $counterBase()
            ->where(function ($q) {
                $q->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= 0')
                    ->orWhere(function ($q2) {
                        $q2->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) > 0')
                            ->whereRaw('COALESCE(ss.sellable_stock, products.stock_quantity) <= products.min_stock')
                            ->where('products.min_stock', '>', 0);
                    });
            })
            ->selectRaw('products.min_stock, products.cost, COALESCE(ss.sellable_stock, products.stock_quantity) AS effective_stock')
            ->get()
            ->sum(fn ($p) => max(0, (float) $p->min_stock - (float) $p->effective_stock) * (float) $p->cost);

        $productIds = $products->pluck('id');
        $supplierMap = ProductBatch::whereIn('product_id', $productIds)
            ->whereNotNull('supplier_id')
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->get(['product_id', 'supplier_id'])
            ->unique('product_id')
            ->pluck('supplier_id', 'product_id');

        $products->each(function ($product) use ($supplierMap) {
            $product->setAttribute('stock_quantity', round((float) $product->effective_stock, 2));
            $product->setAttribute('preferred_supplier_id', $supplierMap->get($product->id));
            unset($product->effective_stock);
        });

        return response()->json([
            ...$products->toArray(),
            'counters' => [
                'out_of_stock' => $outCount,
                'low_stock' => $lowCount,
                'total' => $outCount + $lowCount,
            ],
            'reorder_cost' => round($reorderCost, 2),
        ]);
    }
}
