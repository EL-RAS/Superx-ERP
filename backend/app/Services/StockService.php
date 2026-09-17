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
     * simple products decrement stock_quantity. Throws InsufficientStockException
     * when stock is insufficient, unless negative stock is explicitly allowed.
     *
     * @return array<int, array<string, mixed>> FEFO batch deductions (empty for simple products)
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

        $product->decrement('stock_quantity', $quantity);

        return [];
    }
}
