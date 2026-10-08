<?php

namespace App\Services;

use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Scopes\BusinessScope;

/**
 * Single source of truth for "what is the stock on hand worth?".
 *
 * Two costing shapes are supported:
 *
 *  - Batch-managed products are valued from their live batches
 *    (remaining quantity x cost_per_unit) - the exact cost that was paid when
 *    the batch was received, which is also the cost COGS is booked at.
 *
 *  - Simple (non-batched) products are valued from a FIFO stack of cost
 *    layers built out of the historical goods-receipt lines for the product.
 *    On-hand units are the newest layers; any on-hand units that are not
 *    covered by a recorded receipt (opening stock, manual adjustments) form a
 *    leading layer carried at the product's current weighted-average cost.
 *    This replaces the old "stock_quantity x product.cost" formula whenever
 *    real GRN history exists, so valuation follows what was actually paid.
 *
 * Every number produced here is consumed by InventorySyncService::sync()
 * (which forces the 1030 Inventory Asset balance onto the calculated value)
 * and by ReportService::stockValuation() (which must report the very same
 * figure the ledger is reconciled to).
 */
class InventoryValuationService
{
    private const EPSILON = 0.000001;

    /**
     * Cost layers from recorded goods receipts, oldest receipt first.
     *
     * @return array<int, array{quantity: float, unit_cost: float}>
     */
    public function receiptLayers(string $businessId, int|string $productId): array
    {
        $rows = GoodsReceiptItem::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->get(['quantity', 'unit_cost']);

        return $rows
            ->map(fn (GoodsReceiptItem $row) => [
                'quantity' => round((float) $row->quantity, 4),
                'unit_cost' => round((float) $row->unit_cost, 4),
            ])
            ->all();
    }

    /**
     * All products' receipt layers for a business in one query.
     *
     * @return array<string, array<int, array{quantity: float, unit_cost: float}>>
     */
    public function receiptLayersByProduct(string $businessId): array
    {
        $map = [];

        GoodsReceiptItem::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->whereNotNull('product_id')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->get(['product_id', 'quantity', 'unit_cost'])
            ->each(function (GoodsReceiptItem $row) use (&$map) {
                $map[(string) $row->product_id][] = [
                    'quantity' => round((float) $row->quantity, 4),
                    'unit_cost' => round((float) $row->unit_cost, 4),
                ];
            });

        return $map;
    }

    /**
     * The full cost stack a product's on-hand stock is drawn from:
     * unrecorded (opening / manual) stock first, then every goods receipt in
     * receipt order.
     *
     * @param  array<int, array{quantity: float, unit_cost: float}>  $receiptLayers
     * @return array<int, array{quantity: float, unit_cost: float}>
     */
    public function onHandLayers(Product $product, array $receiptLayers): array
    {
        $onHand = (float) $product->stock_quantity;
        $recorded = $this->layersQuantity($receiptLayers);
        $unrecorded = $onHand - $recorded;

        if ($unrecorded <= self::EPSILON) {
            return $receiptLayers;
        }

        $carry = (float) $product->cost;
        if ($carry <= self::EPSILON) {
            $carry = $this->layersAverageCost($receiptLayers);
        }

        return array_merge(
            [['quantity' => round($unrecorded, 4), 'unit_cost' => round($carry, 4)]],
            $receiptLayers,
        );
    }

    /**
     * Units of the stack that have already been consumed (FIFO: oldest go
     * first), i.e. the offset at which the on-hand region starts.
     *
     * @param  array<int, array{quantity: float, unit_cost: float}>  $layers
     */
    public function onHandOffset(array $layers, float $onHand): float
    {
        return max(0.0, $this->layersQuantity($layers) - $onHand);
    }

    /**
     * Take $quantity units starting at $offset units into the stack.
     * Any overshoot (negative stock allowed) is carried at the last layer's
     * cost, falling back to $fallbackCost when the stack is empty.
     *
     * @param  array<int, array{quantity: float, unit_cost: float}>  $layers
     * @return array<int, array{quantity: float, unit_cost: float}>
     */
    public function sliceLayers(array $layers, float $offset, float $quantity, float $fallbackCost = 0.0): array
    {
        if ($quantity <= self::EPSILON) {
            return [];
        }

        $taken = [];
        $skip = max(0.0, $offset);
        $remaining = $quantity;
        $lastCost = $fallbackCost;

        foreach ($layers as $layer) {
            $layerQty = (float) $layer['quantity'];
            $lastCost = (float) $layer['unit_cost'];

            if ($layerQty <= self::EPSILON) {
                continue;
            }

            if ($skip >= $layerQty - self::EPSILON) {
                $skip -= $layerQty;

                continue;
            }

            $take = min($layerQty - $skip, $remaining);
            if ($take > self::EPSILON) {
                $taken[] = ['quantity' => round($take, 4), 'unit_cost' => round($lastCost, 4)];
                $remaining -= $take;
            }

            $skip = 0.0;
        }

        if ($remaining > self::EPSILON) {
            $taken[] = ['quantity' => round($remaining, 4), 'unit_cost' => round($lastCost, 4)];
        }

        return $taken;
    }

    /**
     * @param  array<int, array{quantity: float, unit_cost: float}>  $layers
     */
    public function layersQuantity(array $layers): float
    {
        return round(array_sum(array_map(fn (array $layer) => (float) $layer['quantity'], $layers)), 4);
    }

    /**
     * @param  array<int, array{quantity: float, unit_cost: float}>  $layers
     */
    public function layersValue(array $layers): float
    {
        return array_sum(array_map(
            fn (array $layer) => (float) $layer['quantity'] * (float) $layer['unit_cost'],
            $layers,
        ));
    }

    /**
     * @param  array<int, array{quantity: float, unit_cost: float}>  $layers
     */
    private function layersAverageCost(array $layers): float
    {
        $quantity = $this->layersQuantity($layers);

        if ($quantity <= self::EPSILON) {
            return 0.0;
        }

        return $this->layersValue($layers) / $quantity;
    }

    /**
     * Value of the remaining active, non-expired batch stock for a product.
     */
    public function batchValue(string $businessId, int|string $productId): float
    {
        $batches = ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now());
            })
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->get(['quantity', 'quantity_sold', 'cost_per_unit']);

        return round($batches->sum(fn (ProductBatch $batch) => $batch->remaining_quantity * (float) $batch->cost_per_unit), 2);
    }

    /**
     * Weighted-average cost per base unit currently on hand.
     *
     * Batch products derive it from their live batches (the cost that will be
     * booked when they are sold). Simple products derive it from the cost
     * stack - historical goods receipts blended with any unrecorded stock -
     * and fall back to the product's stored cost when there is nothing to
     * value or the stack cannot explain a positive value.
     *
     * @param  array<int, array{quantity: float, unit_cost: float}>|null  $receiptLayers
     */
    public function weightedAverageCost(string $businessId, Product $product, ?array $receiptLayers = null): float
    {
        if ($product->has_batch) {
            $quantity = 0.0;
            $value = 0.0;

            ProductBatch::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $businessId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('expiry_date')
                        ->orWhere('expiry_date', '>=', now());
                })
                ->whereRaw('(quantity - quantity_sold) > 0')
                ->get(['quantity', 'quantity_sold', 'cost_per_unit'])
                ->each(function (ProductBatch $batch) use (&$quantity, &$value) {
                    $quantity += (float) $batch->remaining_quantity;
                    $value += $batch->remaining_quantity * (float) $batch->cost_per_unit;
                });

            if ($quantity > 0.001) {
                return round($value / $quantity, 4);
            }

            return (float) $product->cost;
        }

        $onHand = (float) $product->stock_quantity;
        if ($onHand <= 0.001) {
            return (float) $product->cost;
        }

        $layers = $this->onHandLayers($product, $receiptLayers ?? $this->receiptLayers($businessId, (int) $product->id));
        $value = $this->layersValue($this->sliceLayers($layers, $this->onHandOffset($layers, $onHand), $onHand, (float) $product->cost));

        if ($value <= 0.004) {
            return (float) $product->cost;
        }

        return round($value / $onHand, 4);
    }

    /**
     * On-hand value of a single product.
     *
     * @param  array<int, array{quantity: float, unit_cost: float}>|null  $receiptLayers
     */
    public function productValue(string $businessId, Product $product, ?array $receiptLayers = null): float
    {
        if ($product->has_batch) {
            return $this->batchValue($businessId, (int) $product->id);
        }

        $onHand = (float) $product->stock_quantity;
        if ($onHand <= 0.001) {
            return 0.0;
        }

        $layers = $this->onHandLayers($product, $receiptLayers ?? $this->receiptLayers($businessId, (int) $product->id));
        $value = $this->layersValue($this->sliceLayers($layers, $this->onHandOffset($layers, $onHand), $onHand, (float) $product->cost));

        if ($value <= 0.004) {
            return round($onHand * (float) $product->cost, 2);
        }

        return round($value, 2);
    }

    /**
     * Total on-hand stock value for a business.
     */
    public function stockValue(string $businessId): float
    {
        $receiptLayers = $this->receiptLayersByProduct($businessId);
        $total = 0.0;

        Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->get()
            ->each(function (Product $product) use ($businessId, $receiptLayers, &$total) {
                $total += $this->productValue(
                    $businessId,
                    $product,
                    $receiptLayers[(string) $product->id] ?? [],
                );
            });

        return round($total, 2);
    }
}
