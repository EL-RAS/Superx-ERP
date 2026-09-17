<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\StockMovement;

/**
 * Invoice line-level business rules shared across the invoice write paths
 * (store / update / void), keeping the controller thin and single-sourced:
 *
 *  - line tax/totals computation + InvoiceItem persistence
 *  - promotion usage analytics persistence
 *  - stock restoration on void (exact FEFO reversal, best-effort legacy
 *    restore, simple-product replenishment)
 */
class InvoiceService
{
    /**
     * Persist the invoice's line items (applying the per-line tax split) and
     * return the running totals the caller folds into the invoice aggregates.
     *
     * @return array{subtotal: float, tax: float, discount: float}
     */
    public function createItems(Invoice $invoice, array $items, bool $taxEnabled): array
    {
        $subtotal = 0;
        $totalTax = 0;
        $totalDiscount = 0;

        foreach ($items as $item) {
            $lineTotal = $item['quantity'] * $item['unit_price'];
            $lineDiscount = $item['discount'] ?? 0;
            $rate = $item['tax_rate'] ?? 0;

            $split = TaxCalculator::line($lineTotal, $rate, $taxEnabled, $lineDiscount);
            $lineNet = $split['net'];
            $lineTax = $split['tax'];

            $subtotal += $lineTotal;
            $totalTax += $lineTax;
            $totalDiscount += $lineDiscount;

            InvoiceItem::create([
                'business_id' => $invoice->business_id,
                'invoice_id' => $invoice->id,
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount' => $lineDiscount,
                'tax_rate' => $item['tax_rate'] ?? 0,
                'tax_amount' => round($lineTax, 2),
                'total' => round($lineNet + $lineTax, 2),
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $totalTax,
            'discount' => $totalDiscount,
        ];
    }

    /**
     * Persist promotion analytics for a completed sale. Each applied promotion
     * gets a usage row (discount actually given + the cart revenue it is
     * attributed to, split proportionally by discount share so aggregate totals
     * never double count when several promotions share one cart). The breakdown
     * is also mirrored into the invoice metadata for auditability.
     */
    public function recordPromotionUsage(Invoice $invoice, array $promotions): void
    {
        $applied = [];
        foreach ($promotions as $promo) {
            $discount = round((float) ($promo['discount'] ?? 0), 2);
            if ($discount > 0) {
                $applied[] = ['id' => (int) $promo['id'], 'discount' => $discount];
            }
        }

        if (count($applied) === 0) {
            return;
        }

        $grossRevenue = round((float) $invoice->subtotal + (float) $invoice->tax_amount, 2);
        $totalDiscount = array_sum(array_column($applied, 'discount'));

        foreach ($applied as $entry) {
            $share = $totalDiscount > 0 ? $entry['discount'] / $totalDiscount : 0;
            PromotionUsage::create([
                'business_id' => $invoice->business_id,
                'promotion_id' => $entry['id'],
                'invoice_id' => $invoice->id,
                'discount_amount' => $entry['discount'],
                'associated_revenue' => round($grossRevenue * $share, 2),
            ]);
            Promotion::where('id', $entry['id'])->increment('current_uses');
        }

        $invoice->update([
            'metadata' => array_merge($invoice->metadata ?? [], ['promotions' => $applied]),
        ]);
    }

    /**
     * Put stock back when an invoice is voided. Batch products reverse the
     * exact FEFO deductions recorded on the item (quantity_sold decremented);
     * legacy batch items without deduction metadata are best-effort restored
     * to the earliest active batch. Simple products get their stock_quantity
     * incremented. A StockMovement records each addition.
     */
    public function restoreStockForVoid(Invoice $invoice, int $userId): void
    {
        $businessId = $invoice->business_id;

        foreach ($invoice->items as $item) {
            if (empty($item->product_id)) {
                continue;
            }

            $product = Product::find($item->product_id);
            if (! $product) {
                continue;
            }

            $qty = (float) $item->quantity;

            if ($product->has_batch) {
                $deductions = $item->metadata['deductions'] ?? null;
                if (is_array($deductions) && count($deductions) > 0) {
                    foreach ($deductions as $deduction) {
                        $batch = ProductBatch::find($deduction['batch_id'] ?? null);
                        if (! $batch || (string) $batch->product_id !== (string) $product->id) {
                            continue;
                        }
                        $batch->decrement('quantity_sold', (float) $deduction['quantity']);
                    }
                } else {
                    $batch = ProductBatch::where('product_id', $product->id)
                        ->where('is_active', true)
                        ->orderBy('expiry_date')
                        ->orderBy('id')
                        ->first();
                    if ($batch) {
                        $batch->increment('quantity', $qty);
                    }
                }

                $product->fresh()->recalculateStockQuantity();
                $product->fresh()->updateWeightedAverageCost();
            } else {
                $product->increment('stock_quantity', $qty);
            }

            StockMovement::create([
                'business_id' => $businessId,
                'product_id' => $item->product_id,
                'quantity' => $qty,
                'type' => 'addition',
                'reference_type' => 'invoice_void',
                'reference_id' => $invoice->id,
                'notes' => 'Void - '.$invoice->invoice_number,
            ]);
        }
    }
}
