<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\InventoryAdjustment;
use App\Models\PurchaseOrderPayment;
use Illuminate\Database\Eloquent\Collection;

/**
 * Supplier payable ledger helpers.
 *
 * Accounts payable is recognized ONLY on goods receipt — approving a
 * purchase order has no financial effect. A supplier's open payable is:
 *
 *     Σ(payable credit across goods receipts) − Σ(settlement payments) − Σ(purchase returns)
 *
 * A receipt's "payable credit" is total_amount minus pay_now_amount: the
 * portion posted to Account 2010. Cash/bank paid at the door (pay_now_amount)
 * is posted straight to the cash/bank account and leaves no payable.
 * Payments recorded in the same transaction as a cash/bank goods receipt
 * (metadata source "goods_receipt_direct") already zero the payable and are
 * excluded from the settlement sum so they cannot be double-counted against
 * the supplier.
 */
class SupplierLedgerService
{
    /**
     * Query of the goods receipts that left a payable on the supplier —
     * i.e. a payable-credit leg (total_amount − pay_now_amount) greater than
     * zero → Cr 2010 was posted for that leg. Both direct
     * (purchase_order_id IS NULL) and PO-linked receipts count.
     */
    public static function creditReceiptsQuery()
    {
        return GoodsReceipt::whereRaw('(total_amount - COALESCE(pay_now_amount, 0)) > 0.005');
    }

    /**
     * Completed payments that settle an open payable. Direct cash-outs
     * recorded at goods-receipt time (source "goods_receipt_direct") already
     * zero the payable within the same transaction and are filtered out.
     *
     * @return Collection<int, PurchaseOrderPayment>
     */
    public static function settlementPayments(): Collection
    {
        return PurchaseOrderPayment::query()
            ->where('status', 'completed')
            ->get([
                'id',
                'purchase_order_id',
                'goods_receipt_id',
                'supplier_id',
                'amount',
                'method',
                'payment_number',
                'reference_number',
                'created_at',
                'metadata',
            ])
            ->filter(fn (PurchaseOrderPayment $payment) => ($payment->metadata['source'] ?? null) !== 'goods_receipt_direct')
            ->values();
    }

    /**
     * Inventory adjustments that reduce a supplier's payable: purchase-return
     * debit notes and waste/damage adjustments claimed against the supplier
     * (metadata.responsibility = 'supplier'). Auto-generated companion debit
     * notes (metadata.source_claim_id set) are excluded — the source claim
     * already carries the debit value, so including the companion would
     * double-count the reduction.
     */
    public static function supplierLiabilityAdjustmentsQuery()
    {
        return InventoryAdjustment::where(function ($query) {
            $query->where('type', 'purchase_return')
                ->orWhereRaw("COALESCE(metadata->>'responsibility', '') = 'supplier'");
        })->whereRaw("COALESCE(metadata->>'source_claim_id', '') = ''");
    }

    /**
     * Value of supplier liability debit notes (purchase returns + supplier
     * claims) raised against the supplier.
     */
    public static function returnsValue(int|string $supplierId): float
    {
        $value = 0.0;

        static::supplierLiabilityAdjustmentsQuery()
            ->get(['unit_cost', 'quantity_adjusted', 'metadata'])
            ->each(function (InventoryAdjustment $adjustment) use ($supplierId, &$value) {
                if ((string) ($adjustment->metadata['supplier_id'] ?? '') === (string) $supplierId) {
                    $value += abs((float) $adjustment->quantity_adjusted) * (float) ($adjustment->unit_cost ?? 0);
                }
            });

        return round($value, 2);
    }

    /**
     * Net payable balance of a supplier. Negative = prepayment (payments
     * recorded before goods arrive).
     */
    public static function balanceFor(int|string $supplierId): float
    {
        $grnTotal = (float) static::creditReceiptsQuery()
            ->where('supplier_id', $supplierId)
            ->get(['total_amount', 'pay_now_amount'])
            ->sum('payable_credit');

        $paid = (float) static::settlementPayments()
            ->where('supplier_id', $supplierId)
            ->sum('amount');

        return round($grnTotal - $paid - static::returnsValue($supplierId), 2);
    }
}
