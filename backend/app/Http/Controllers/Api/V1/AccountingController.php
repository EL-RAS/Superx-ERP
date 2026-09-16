<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Scopes\BusinessScope;
use App\Services\SupplierLedgerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AR / AP subledger views for the Accounting module.
 *
 * Accounts Receivable (1040) is derived from the invoice sub-ledger:
 * every non-void invoice increases the customer balance, completed
 * payments decrease it, and refunded amounts restore it. Accounts
 * Payable (2010) mirrors the goods-receipt sub-ledger: a payable is
 * recognized only when goods arrive (credit-method GRN → Cr 2010), never
 * on PO approval. Settling payments and purchase-return debit notes
 * reduce the balance.
 */
class AccountingController extends Controller
{
    public function receivables(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $invoices = Invoice::with(['customer:id,name,phone,email', 'payments:id,invoice_id,amount,status,refunded_amount'])
            ->where('business_id', $businessId)
            ->whereNotIn('status', ['draft', 'void'])
            ->orderBy('created_at')
            ->get(['id', 'customer_id', 'invoice_number', 'created_at', 'net_amount', 'payment_status']);

        // Sales returns reduce the receivable: sum the value (revenue + tax)
        // reversed per invoice through sales_return entries.
        $returnsByInvoice = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('reference_type', 'sales_return')
            ->get(['id', 'metadata'])
            ->filter(fn (JournalEntry $entry) => ! empty($entry->metadata['invoice_id']))
            ->groupBy(fn (JournalEntry $entry) => (string) $entry->metadata['invoice_id'])
            ->map(fn ($group) => round(
                $group->sum(fn (JournalEntry $entry) => (float) ($entry->metadata['revenue'] ?? 0) + (float) ($entry->metadata['tax'] ?? 0)),
                2
            ));

        $aggregates = [];
        $totalOutstanding = 0;

        foreach ($invoices as $invoice) {
            if (empty($invoice->customer_id)) {
                continue;
            }

            $paid = round((float) $invoice->payments->where('status', 'completed')->sum('amount')
                - (float) $invoice->payments->sum('refunded_amount'), 2);
            $returned = (float) ($returnsByInvoice[$invoice->id] ?? 0);
            $balance = round((float) $invoice->net_amount - $paid - $returned, 2);

            if ($balance <= 0.005) {
                continue;
            }

            $key = (string) $invoice->customer_id;
            $aggregates[$key] ??= [
                'customer_id' => $invoice->customer_id,
                'name' => $invoice->customer?->name ?? 'Unknown Customer',
                'phone' => $invoice->customer?->phone,
                'email' => $invoice->customer?->email,
                'open_invoices_count' => 0,
                'outstanding' => 0,
                'invoices' => [],
            ];

            $aggregates[$key]['open_invoices_count']++;
            $aggregates[$key]['outstanding'] += $balance;
            $aggregates[$key]['invoices'][] = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'date' => $invoice->created_at->toDateString(),
                'payment_status' => $invoice->payment_status,
                'net_amount' => round((float) $invoice->net_amount, 2),
                'paid' => $paid,
                'balance' => $balance,
            ];

            $totalOutstanding += $balance;
        }

        $customers = collect(array_values($aggregates))
            ->map(function ($customer) {
                $customer['outstanding'] = round($customer['outstanding'], 2);

                return $customer;
            })
            ->sortByDesc('outstanding')
            ->values();

        return response()->json([
            'as_of' => now()->toDateString(),
            'account_code' => '1040',
            'account_name' => 'Accounts Receivable',
            'total_outstanding' => round($totalOutstanding, 2),
            'customers' => $customers,
        ]);
    }

    public function payables(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        // Accounts payable is recognized on goods receipt only — POs carry
        // no payable until stock arrives. Every receipt with a payable-credit
        // leg (total_amount − pay_now_amount > 0) is an open item, whether it
        // was received on credit or paid in part at the door.
        $receipts = GoodsReceipt::with(['supplier:id,name,phone', 'payments'])
            ->where('business_id', $businessId)
            ->whereRaw('(total_amount - COALESCE(pay_now_amount, 0)) > 0.005')
            ->orderBy('supplier_id')
            ->orderBy('created_at')
            ->get();

        $settlementPayments = SupplierLedgerService::settlementPayments();
        $returnsBySupplier = $this->purchaseReturnsBySupplier($businessId);

        // PO-linked receipts share their PO's settlement payments
        // proportionally so an advance paid against the PO lowers the
        // balance of the receipts that follow it.
        $poCreditTotals = $receipts->whereNotNull('purchase_order_id')
            ->groupBy(fn ($receipt) => (string) $receipt->purchase_order_id)
            ->map(fn ($group) => round((float) $group->sum(fn ($r) => (float) $r->payable_credit), 2))
            ->all();

        $aggregates = [];
        $totalOutstanding = 0;

        foreach ($receipts as $receipt) {
            $settlement = $this->receiptSettlement($receipt, $settlementPayments, $poCreditTotals);
            $balance = round((float) $receipt->payable_credit - (float) $settlement['paid'], 2);

            if ($balance <= 0.005) {
                continue;
            }

            $key = (string) $receipt->supplier_id;
            $aggregates[$key] ??= $this->emptySupplierAggregate($receipt->supplier);
            $aggregates[$key]['open_receipts_count']++;
            $aggregates[$key]['outstanding'] += $balance;
            $aggregates[$key]['receipts'][] = [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_date' => $receipt->received_at?->toDateString() ?? $receipt->created_at->toDateString(),
                'status' => $receipt->status,
                'total' => round((float) ($receipt->total_amount ?? 0), 2),
                'paid' => round((float) $settlement['paid'], 2),
                'balance' => $balance,
            ];

            $totalOutstanding += $balance;
        }

        $suppliers = collect($aggregates)
            ->map(function ($supplier) use ($returnsBySupplier) {
                $supplier['outstanding'] = round(
                    (float) $supplier['outstanding'] - (float) ($returnsBySupplier[(string) $supplier['supplier_id']] ?? 0),
                    2
                );
                $supplier['returns_total'] = round((float) ($returnsBySupplier[(string) $supplier['supplier_id']] ?? 0), 2);

                return $supplier;
            })
            ->filter(fn ($supplier) => (float) $supplier['outstanding'] > 0.005)
            ->sortByDesc('outstanding')
            ->values();

        // Purchase returns can offset a supplier's whole balance; any residual
        // paid to suppliers is tracked through their ledger instead.
        $totalOutstanding = round(
            $suppliers->sum(fn ($supplier) => (float) $supplier['outstanding']),
            2
        );

        return response()->json([
            'as_of' => now()->toDateString(),
            'account_code' => '2010',
            'account_name' => 'Accounts Payable',
            'total_outstanding' => $totalOutstanding,
            'suppliers' => $suppliers,
        ]);
    }

    public function payableStatement(Request $request, Supplier $supplier): JsonResponse
    {
        if ((string) $supplier->business_id !== (string) $request->user()->business_id) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $receipts = GoodsReceipt::with(['payments', 'purchaseOrder:id,order_number'])
            ->where('supplier_id', $supplier->id)
            ->whereRaw('(total_amount - COALESCE(pay_now_amount, 0)) > 0.005')
            ->orderBy('created_at', 'desc')
            ->get();

        $settlementPayments = SupplierLedgerService::settlementPayments();

        $poCreditTotals = $receipts->whereNotNull('purchase_order_id')
            ->groupBy(fn ($receipt) => (string) $receipt->purchase_order_id)
            ->map(fn ($group) => round((float) $group->sum(fn ($r) => (float) $r->payable_credit), 2))
            ->all();

        $rows = [];
        $totalOutstanding = 0;

        foreach ($receipts as $receipt) {
            $settlement = $this->receiptSettlement($receipt, $settlementPayments, $poCreditTotals);
            $balance = round((float) $receipt->payable_credit - (float) $settlement['paid'], 2);

            $rows[] = [
                'id' => $receipt->id,
                'kind' => 'goods_receipt',
                'receipt_number' => $receipt->receipt_number,
                'order_date' => $receipt->received_at?->toDateString() ?? $receipt->created_at->toDateString(),
                'status' => $receipt->status,
                'total' => round((float) ($receipt->total_amount ?? 0), 2),
                'paid' => round((float) $settlement['paid'], 2),
                'balance' => $balance,
                'payments' => $settlement['payments']->map(function ($payment) {
                    return [
                        'payment_number' => $payment->payment_number,
                        'date' => $payment->created_at->toDateString(),
                        'amount' => round((float) $payment->amount, 2),
                        'method' => $payment->method,
                    ];
                })->values(),
            ];

            $totalOutstanding += $balance;
        }

        // Credit notes (purchase returns) reverse the balance.
        $returns = $this->purchaseReturnsBySupplier((string) $supplier->business_id)[(string) $supplier->id] ?? 0;
        if ($returns > 0) {
            $rows[] = [
                'id' => null,
                'kind' => 'purchase_return',
                'receipt_number' => 'CREDIT NOTE',
                'order_date' => null,
                'status' => 'completed',
                'total' => -round($returns, 2),
                'paid' => 0,
                'balance' => -round($returns, 2),
                'payments' => [],
            ];
            $totalOutstanding -= $returns;
        }

        return response()->json([
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'phone' => $supplier->phone],
            'account_code' => '2010',
            'account_name' => 'Accounts Payable',
            'total_outstanding' => round(max(0.0, $totalOutstanding), 2),
            'orders' => array_values($rows),
        ]);
    }

    /**
     * Settlement amount attributable to one receipt.
     *
     * PO-linked receipts share their PO's settlement payments (proportionally
     * across the PO's credit receipts), so an advance payment recorded against
     * the PO before goods arrive lowers the receipt's outstanding balance.
     * Direct receipts settle from their own payments; same-transaction
     * cash/bank cash-outs (source "goods_receipt_direct") are skipped.
     *
     * @param  Collection<int, PurchaseOrderPayment>  $settlementPayments
     * @param  array<string, float>  $poCreditTotals
     * @return array{paid: float, payments: Collection<int, PurchaseOrderPayment>}
     */
    private function receiptSettlement(GoodsReceipt $receipt, Collection $settlementPayments, array $poCreditTotals): array
    {
        if ($receipt->purchase_order_id !== null) {
            $poPaid = round((float) $settlementPayments
                ->where('purchase_order_id', $receipt->purchase_order_id)
                ->sum('amount'), 2);
            $poTotal = (float) ($poCreditTotals[(string) $receipt->purchase_order_id] ?? 0);

            $paid = $poTotal > 0
                ? round($poPaid * (float) $receipt->payable_credit / $poTotal, 2)
                : $poPaid;

            $payments = $settlementPayments
                ->where('purchase_order_id', $receipt->purchase_order_id)
                ->values();

            return ['paid' => $paid, 'payments' => $payments];
        }

        $payments = $receipt->payments
            ->where('status', 'completed')
            ->reject(fn ($payment) => ($payment->metadata['source'] ?? null) === 'goods_receipt_direct')
            ->values();

        return ['paid' => round((float) $payments->sum('amount'), 2), 'payments' => $payments];
    }

    /**
     * Value of purchase-return debit notes per supplier, derived from the
     * posted inventory_adjustment entries (metadata carries supplier_id).
     *
     * @return array<string, float>
     */
    private function purchaseReturnsBySupplier(string $businessId): array
    {
        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('reference_type', 'inventory_adjustment')
            ->get(['metadata'])
            ->filter(fn (JournalEntry $entry) => ($entry->metadata['type'] ?? null) === 'purchase_return'
                && ! empty($entry->metadata['supplier_id']))
            ->groupBy(fn (JournalEntry $entry) => (string) $entry->metadata['supplier_id'])
            ->map(fn ($group) => round(
                $group->sum(fn (JournalEntry $entry) => abs((float) ($entry->metadata['quantity_adjusted'] ?? 0)) * (float) ($entry->metadata['unit_cost'] ?? 0)),
                2
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySupplierAggregate(?Supplier $supplier): array
    {
        return [
            'supplier_id' => $supplier?->id,
            'name' => $supplier?->name ?? 'Unknown Supplier',
            'phone' => $supplier?->phone,
            'open_orders_count' => 0,
            'open_receipts_count' => 0,
            'outstanding' => 0,
            'orders' => [],
            'receipts' => [],
            'returns_total' => 0,
        ];
    }
}
