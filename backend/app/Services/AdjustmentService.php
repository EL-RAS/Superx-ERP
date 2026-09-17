<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryAdjustment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Scopes\BusinessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * InventoryAdjustment write paths. A single create() owns the full store
 * transaction (stock movement, refresh, GL posting, companion debit-note
 * generation, sales-return value resolution) and reverse() undoes it exactly,
 * so the controller never hand-rolls a second copy of the rules.
 */
final class AdjustmentService
{
    public const DEDUCTION_TYPES = ['waste', 'damage', 'count_deficit', 'purchase_return'];

    public const RETURN_TYPES = ['return'];

    /**
     * Create a completed adjustment (validated payload expected), moving stock,
     * posting the GL entry, generating the companion purchase-return debit note
     * when requested and posting the sales-return entry for returns.
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(string $businessId, int $userId, array $validated): InventoryAdjustment
    {
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

        return DB::transaction(function () use ($businessId, $userId, $validated, $type, $quantity, $quantityAdjusted, $isDeduction, $isReturn, $responsibility, $liabilityType, $isSupplierLiability) {
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

            $notes = $validated['notes'] ?? $validated['reason'] ?? null;

            $adjustmentNumber = $validated['adjustment_number'] ?? 'ADJ-'.strtoupper(Str::random(8));

            $adjustment = InventoryAdjustment::create([
                'business_id' => $businessId,
                'user_id' => $userId,
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
                $product->refreshMetrics();
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
                $userId
            );

            if ((bool) ($validated['generate_purchase_return'] ?? false) && $isSupplierLiability && in_array($type, ['waste', 'damage'], true)) {
                $companionNote = ($notes ? $notes.' - ' : '').'Auto-generated debit note for supplier '.$type.' claim '.$adjustment->adjustment_number;

                $companion = InventoryAdjustment::create([
                    'business_id' => $businessId,
                    'user_id' => $userId,
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
                        $userId,
                        $validated['refund_method'] ?? 'credit'
                    );
                }
            }

            $adjustment->load([
                'product:id,name,sku,unit',
                'batch:id,batch_number',
                'user:id,name',
            ]);

            return $adjustment;
        });
    }

    /**
     * Undo a completed adjustment inside a transaction: reverse every posted
     * journal entry, move stock back to its pre-adjustment position and delete
     * the row. Mirrors create() exactly (including the companion debit note,
     * whose GL was only ever posted once on the source claim).
     */
    public function reverse(InventoryAdjustment $inventoryAdjustment): void
    {
        DB::transaction(function () use ($inventoryAdjustment) {
            $businessId = $inventoryAdjustment->business_id;
            $accounting = app(AccountingService::class);

            foreach ($inventoryAdjustment->journalEntries()->with('lines')->get() as $entry) {
                $accounting->reverseJournalEntry($businessId, $entry);
            }

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
}
