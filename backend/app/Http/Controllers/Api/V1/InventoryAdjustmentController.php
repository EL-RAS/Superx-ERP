<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Rules\ProductQuantity;
use App\Services\AdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InventoryAdjustmentController extends Controller
{
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

        $adjustment = app(AdjustmentService::class)->create(
            $request->user()->business_id,
            $request->user()->id,
            $validated
        );

        return response()->json($adjustment, 201);
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
        app(AdjustmentService::class)->reverse($inventoryAdjustment);

        return response()->json(['message' => 'Adjustment reversed and deleted.']);
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
        $service = app(AdjustmentService::class);

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
                $service->create(
                    $product->business_id,
                    $request->user()->id,
                    [
                        'product_id' => $product->id,
                        'batch_id' => $batch->id,
                        'type' => 'waste',
                        'quantity' => $remaining,
                        'adjustment_number' => 'AUTO-'.strtoupper(Str::random(8)),
                        'notes' => "Automated Adjustment: Batch expired on {$batch->expiry_date->format('Y-m-d')}",
                        'reason' => "Automated Adjustment: Batch expired on {$batch->expiry_date->format('Y-m-d')}",
                    ]
                );

                $processed++;
            } catch (\Throwable $e) {
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
