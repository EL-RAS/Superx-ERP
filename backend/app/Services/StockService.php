<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductBatch;

class StockService
{
    /**
     * Deduct sellable stock for a sale-like flow.
     *
     * Batch-managed products deduct FEFO (recording the exact batch deductions);
     * simple products walk their cost stack (historical goods-receipt layers,
     * oldest first) so the units that actually left the shelf are priced at
     * what they cost when they were received. Either way the returned
     * deductions are the frozen, audit-grade cost of this sale - they are
     * persisted on invoice_items.metadata['deductions'] and are what the
     * 5010 COGS leg is booked from, so later cost changes can never
     * retroactively rewrite an invoice's margin.
     *
     * Throws InsufficientStockException when stock is insufficient, unless
     * negative stock is explicitly allowed.
     *
     * @return array<int, array<string, mixed>> frozen cost deductions
     */
    public static function deductForSale(
        Product $product,
        float $quantity,
        string $businessId,
        bool $allowNegative = false,
    ): array {
        if ($product->has_batch) {
            $available = ProductBatch::getAvailableFefoStock((int) $product->id, $businessId);
            if (! $allowNegative && $available < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient batch stock for {$product->name}. Available: {$available}, requested: {$quantity}."
                );
            }

            $deductions = ProductBatch::deductFefo((int) $product->id, $businessId, $quantity, $allowNegative);
            $product->fresh()->refreshMetrics();

            return $deductions;
        }

        if (! $allowNegative && (float) $product->stock_quantity < $quantity) {
            throw new InsufficientStockException(
                "Insufficient stock for {$product->name}. Available: {$product->stock_quantity}, requested: {$quantity}."
            );
        }

        $valuation = app(InventoryValuationService::class);
        $onHand = (float) $product->stock_quantity;
        $layers = $valuation->onHandLayers(
            $product,
            $valuation->receiptLayers($businessId, (int) $product->id),
        );

        $deductions = $valuation->sliceLayers(
            $layers,
            $valuation->onHandOffset($layers, $onHand),
            $quantity,
            (float) $product->cost,
        );

        $product->decrement('stock_quantity', $quantity);

        return array_map(fn (array $layer) => [
            'batch_id' => null,
            'batch_number' => null,
            'quantity' => $layer['quantity'],
            'unit_cost' => $layer['unit_cost'],
        ], $deductions);
    }
}
