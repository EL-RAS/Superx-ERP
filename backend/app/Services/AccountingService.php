<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\InventoryAdjustment;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrderPayment;
use App\Models\ReturnExchange;
use App\Models\Shift;
use App\Scopes\BusinessScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AccountingService
{
    /**
     * Post a balanced journal entry. Lines use 'code' (chart of accounts code)
     * or 'account_id'. The entry is created as posted and account balances are
     * recomputed.
     *
     * @param  array<string, mixed>  $meta  date, description, reference_type, reference_id, user_id, entry_prefix
     * @param  array<int, array<string, mixed>>  $lines  account/code, debit, credit, description
     */
    public function post(string $businessId, array $meta, array $lines): JournalEntry
    {
        $this->ensureChartOfAccounts($businessId);

        $resolvedLines = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as $line) {
            $accountId = $line['account_id'] ?? null;
            if (! $accountId && isset($line['code'])) {
                $accountId = $this->accountByCode($businessId, $line['code'])?->id;
            }
            if (! $accountId) {
                throw new \RuntimeException('Unknown account for journal line: '.($line['code'] ?? $line['account_id'] ?? '?'));
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            $totalDebit += $debit;
            $totalCredit += $credit;

            $resolvedLines[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if (abs(round($totalDebit, 2) - round($totalCredit, 2)) > 0.01) {
            throw new \RuntimeException(
                "Journal entry does not balance. Debit: {$totalDebit}, Credit: {$totalCredit}."
            );
        }

        $prefix = $meta['entry_prefix'] ?? 'AUTO';
        $entry = JournalEntry::create([
            'business_id' => $businessId,
            'entry_number' => ($meta['entry_number'] ?? null) ?: $prefix.'-'.strtoupper(Str::random(8)),
            'date' => $meta['date'] ?? now()->toDateString(),
            'description' => $meta['description'] ?? '',
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'shift_id' => $meta['shift_id'] ?? null,
            'user_id' => $meta['user_id'] ?? null,
            'metadata' => $meta['metadata'] ?? null,
            'is_posted' => true,
        ]);

        foreach ($resolvedLines as $line) {
            JournalEntryLine::create([
                'business_id' => $businessId,
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
            ]);
        }

        $this->updateAccountBalances($entry);

        return $entry;
    }

    /**
     * Recompute cached balance metadata for every account touched by an entry.
     */
    public function updateAccountBalances(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');

        foreach ($entry->lines as $line) {
            $account = Account::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $entry->business_id)
                ->find($line->account_id);

            if (! $account) {
                continue;
            }

            $query = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $entry->business_id)
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q
                    ->withoutGlobalScope(BusinessScope::class)
                    ->where('business_id', $entry->business_id)
                    ->where('is_posted', true));

            $totalDebit = (clone $query)->sum('debit');
            $totalCredit = (clone $query)->sum('credit');

            $balance = match ($account->type) {
                'liability', 'equity', 'revenue' => $totalCredit - $totalDebit,
                default => $totalDebit - $totalCredit,
            };

            $account->update([
                'metadata' => array_merge($account->metadata ?? [], ['balance' => $balance]),
            ]);
        }
    }

    public function accountByCode(string $businessId, string $code): ?Account
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('code', $code)
            ->first();
    }

    /**
     * Create the default chart of accounts for a business, skipping any codes
     * that already exist. Safe to call on every transaction.
     */
    public function ensureChartOfAccounts(string $businessId): void
    {
        $existing = Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->pluck('id', 'code');

        $idsByCode = $existing->toArray();

        foreach (self::DEFAULT_CHART as $entry) {
            if (isset($idsByCode[$entry['code']])) {
                continue;
            }

            $account = Account::create([
                'business_id' => $businessId,
                'code' => $entry['code'],
                'name' => $entry['name'],
                'type' => $entry['type'],
                'parent_id' => $entry['parent'] ?? null ? ($idsByCode[$entry['parent']] ?? null) : null,
                'is_active' => true,
                'metadata' => [
                    'name_ar' => $entry['name_ar'],
                    'is_system' => true,
                ],
            ]);

            $idsByCode[$entry['code']] = $account->id;
        }
    }

    public const DEFAULT_CHART = [
        ['code' => '1000', 'name' => 'Assets', 'name_ar' => 'الأصول', 'type' => 'asset'],
        ['code' => '1005', 'name' => 'Main Safe / General Cash', 'name_ar' => 'الصندوق الرئيسي / الخزنة', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '1010', 'name' => 'Cash on Hand / Drawer', 'name_ar' => 'صندوق الكاشير', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '1020', 'name' => 'Bank Account', 'name_ar' => 'الحساب البنكي', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '1030', 'name' => 'Inventory Asset', 'name_ar' => 'قيمة البضاعة / المخزون', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '1040', 'name' => 'Accounts Receivable', 'name_ar' => 'ذمم مدينة', 'type' => 'asset', 'parent' => '1000'],
        ['code' => '2000', 'name' => 'Liabilities', 'name_ar' => 'الالتزامات', 'type' => 'liability'],
        ['code' => '2010', 'name' => 'Accounts Payable / Suppliers', 'name_ar' => 'ذمم الموردين', 'type' => 'liability', 'parent' => '2000'],
        ['code' => '2020', 'name' => 'Sales Tax Payable', 'name_ar' => 'ضريبة المبيعات المستحقة', 'type' => 'liability', 'parent' => '2000'],
        ['code' => '3000', 'name' => 'Equity', 'name_ar' => 'حقوق الملكية', 'type' => 'equity'],
        ['code' => '3010', 'name' => "Owner's Capital", 'name_ar' => 'رأس المال', 'type' => 'equity', 'parent' => '3000'],
        ['code' => '3020', 'name' => 'Retained Earnings', 'name_ar' => 'الأرباح المدورة', 'type' => 'equity', 'parent' => '3000'],
        ['code' => '4000', 'name' => 'Revenue', 'name_ar' => 'الإيرادات', 'type' => 'revenue'],
        ['code' => '4010', 'name' => 'Retail Sales Revenue', 'name_ar' => 'إيراد المبيعات', 'type' => 'revenue', 'parent' => '4000'],
        ['code' => '4020', 'name' => 'Other Revenue', 'name_ar' => 'إيرادات أخرى', 'type' => 'revenue', 'parent' => '4000'],
        ['code' => '4030', 'name' => 'Inventory Adjustment Revenue', 'name_ar' => 'إيراد تسوية المخزون', 'type' => 'revenue', 'parent' => '4000'],
        ['code' => '4040', 'name' => 'Cash Surplus Revenue', 'name_ar' => 'إيراد الفائض النقدي', 'type' => 'revenue', 'parent' => '4000'],
        ['code' => '5000', 'name' => 'Expenses', 'name_ar' => 'المصاريف', 'type' => 'expense'],
        ['code' => '5010', 'name' => 'Cost of Goods Sold - COGS', 'name_ar' => 'تكلفة البضاعة المباعة', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5020', 'name' => 'Inventory Waste / Damage Expense', 'name_ar' => 'مصروف تالف ومفقودات', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5030', 'name' => 'Inventory Adjustment Gain / Loss', 'name_ar' => 'إيراد/خسارة تسوية المخزون', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5040', 'name' => 'Utilities Expense', 'name_ar' => 'كهرباء وماء', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5041', 'name' => 'Rent Expense', 'name_ar' => 'مصروف الإيجار', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5042', 'name' => 'Cash Shortage Expense', 'name_ar' => 'مصروف العجز النقدي', 'type' => 'expense', 'parent' => '5000'],
        ['code' => '5050', 'name' => 'Salaries Expense', 'name_ar' => 'الرواتب والأجور', 'type' => 'expense', 'parent' => '5000'],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Automated journal entry hooks (system-wide accounting integration)
    // Every hook is idempotent: a reference_type + reference_id pair can only
    // be posted once, and postings are skipped inside closed fiscal years.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sales: post the sale itself. Credit sale → Dr 1040 (total), Cr 4010
     * (subtotal), Cr 2020 (tax). Inventory cost → Dr 5010 (COGS), Cr 1030.
     */
    public function postSaleEntry(string $businessId, Invoice $invoice, ?int $userId = null): ?JournalEntry
    {
        return $this->postIfNeeded($businessId, $this->saleEntryMeta($invoice, $userId), $this->saleEntryLines($invoice));
    }

    /**
     * Sale recognition metadata for the automated sale entry.
     *
     * @return array<string, mixed>
     */
    private function saleEntryMeta(Invoice $invoice, ?int $userId): array
    {
        return [
            'date' => $invoice->created_at->toDateString(),
            'description' => 'Sale - '.$invoice->invoice_number,
            'reference_type' => 'sale',
            'reference_id' => $invoice->id,
            'shift_id' => $invoice->shift_id,
            'user_id' => $userId,
            'metadata' => $this->saleMetadata($invoice),
        ];
    }

    /**
     * Sale recognition lines: Dr 1040 (net), Cr 4010 (revenue), Cr 2020 (tax)
     * plus the COGS leg Dr 5010 / Cr 1030 when products are on the invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function saleEntryLines(Invoice $invoice): array
    {
        $net = round((float) $invoice->net_amount, 2);
        $tax = round((float) $invoice->tax_amount, 2);
        $revenue = round($net - $tax, 2);

        $lines = [];
        if ($net > 0) {
            $lines[] = ['code' => '1040', 'debit' => $net, 'description' => 'Invoice '.$invoice->invoice_number];
            $lines[] = ['code' => '4010', 'credit' => $revenue, 'description' => 'Invoice '.$invoice->invoice_number];
            if ($tax > 0) {
                $lines[] = ['code' => '2020', 'credit' => $tax, 'description' => 'Invoice '.$invoice->invoice_number];
            }
        }

        $cogs = $this->invoiceCogs($invoice);
        if ($cogs > 0) {
            $lines[] = ['code' => '5010', 'debit' => $cogs, 'description' => 'COGS - Invoice '.$invoice->invoice_number];
            $lines[] = ['code' => '1030', 'credit' => $cogs, 'description' => 'COGS - Invoice '.$invoice->invoice_number];
        }

        return $lines;
    }

    /**
     * Batch ids deducted from stock for a sale (stored on each item's
     * metadata['deductions']) plus the originating shift id — used for
     * auditability of the COGS leg.
     */
    private function saleMetadata(Invoice $invoice): array
    {
        if (! $invoice->relationLoaded('items')) {
            $invoice->load('items');
        }

        $batchIds = [];
        foreach ($invoice->items as $item) {
            foreach ((array) ($item->metadata['deductions'] ?? []) as $deduction) {
                if (isset($deduction['batch_id'])) {
                    $batchIds[$deduction['batch_id']] = true;
                }
            }
        }

        return [
            'invoice_id' => $invoice->id,
            'deducted_batch_ids' => array_keys($batchIds),
        ];
    }

    /**
     * Sales: post a payment against an invoice (clears 1040).
     * Cash → Dr 1005, bank/card/other → Dr 1020; Cr 1040.
     */
    public function postInvoicePaymentEntry(string $businessId, Payment $payment, ?int $userId = null): ?JournalEntry
    {
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $cashCode = $this->cashAccountCode($payment->method, $businessId, $payment->user_id, $payment->shift_id);
        $date = $payment->created_at?->toDateString() ?? now()->toDateString();

        return $this->postIfNeeded($businessId, [
            'date' => $date,
            'description' => 'Payment received - '.($payment->payment_number ?? 'payment #'.$payment->id),
            'reference_type' => 'invoice_payment',
            'reference_id' => $payment->id,
            'shift_id' => $payment->shift_id,
            'user_id' => $userId ?? $payment->user_id,
        ], [
            ['code' => $cashCode, 'debit' => $amount, 'description' => 'Payment '.($payment->payment_number ?? $payment->id)],
            ['code' => '1040', 'credit' => $amount, 'description' => 'Payment '.($payment->payment_number ?? $payment->id)],
        ]);
    }

    /**
     * Sales: reverse a previously posted payment entry (refund or delete).
     */
    public function postPaymentRefund(string $businessId, Payment $payment, float $amount, ?int $userId = null): ?JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $cashCode = $this->paymentCashAccount($businessId, $payment);

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => 'Payment reversed - '.($payment->payment_number ?? 'payment #'.$payment->id),
            'reference_type' => 'payment_refund',
            'reference_id' => $payment->id,
            'user_id' => $userId ?? $payment->user_id,
        ], [
            ['code' => '1040', 'debit' => $amount, 'description' => 'Reversal '.($payment->payment_number ?? $payment->id)],
            ['code' => $cashCode, 'credit' => $amount, 'description' => 'Reversal '.($payment->payment_number ?? $payment->id)],
        ]);
    }

    /**
     * Sales: reverse the whole sale entry when an invoice is voided.
     * Mirrors the original posted entry line-for-line (same amounts, reversed
     * debit/credit sides) so voiding is exact even when COGS was batch-based.
     */
    public function postVoidInvoice(string $businessId, Invoice $invoice, ?int $userId = null): ?JournalEntry
    {
        $original = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('reference_type', 'sale')
            ->where('reference_id', $invoice->id)
            ->with('lines')
            ->first();

        $lines = [];

        if ($original && $original->lines->isNotEmpty()) {
            foreach ($original->lines as $line) {
                if ($line->debit > 0) {
                    $lines[] = [
                        'account_id' => $line->account_id,
                        'credit' => $line->debit,
                        'description' => 'Void '.$invoice->invoice_number,
                    ];
                }
                if ($line->credit > 0) {
                    $lines[] = [
                        'account_id' => $line->account_id,
                        'debit' => $line->credit,
                        'description' => 'Void '.$invoice->invoice_number,
                    ];
                }
            }
        } else {
            // No previously posted sale entry — build the reversal from scratch.
            $net = round((float) $invoice->net_amount, 2);
            $tax = round((float) $invoice->tax_amount, 2);
            $revenue = round($net - $tax, 2);

            if ($net > 0) {
                $lines[] = ['code' => '1040', 'credit' => $net, 'description' => 'Void '.$invoice->invoice_number];
                $lines[] = ['code' => '4010', 'debit' => $revenue, 'description' => 'Void '.$invoice->invoice_number];
                if ($tax > 0) {
                    $lines[] = ['code' => '2020', 'debit' => $tax, 'description' => 'Void '.$invoice->invoice_number];
                }
            }

            $cogs = $this->invoiceCogs($invoice);
            if ($cogs > 0) {
                $lines[] = ['code' => '5010', 'credit' => $cogs, 'description' => 'COGS reversal - '.$invoice->invoice_number];
                $lines[] = ['code' => '1030', 'debit' => $cogs, 'description' => 'COGS reversal - '.$invoice->invoice_number];
            }
        }

        if (empty($lines)) {
            return null;
        }

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => 'Void - '.$invoice->invoice_number,
            'reference_type' => 'void_sale',
            'reference_id' => $invoice->id,
            'user_id' => $userId,
            'metadata' => $this->saleMetadata($invoice),
        ], $lines);
    }

    /**
     * Reversal: mirror an already-posted entry line-for-line (debit ↔ credit)
     * so deleting/undoing a source transaction reverses its GL effect exactly.
     * Idempotent via a dedicated "reversal_{reference_type}" key, so calling
     * this twice never double-posts.
     */
    public function reverseJournalEntry(string $businessId, JournalEntry $original, ?int $userId = null): ?JournalEntry
    {
        $original->loadMissing('lines');

        if ($original->lines->isEmpty()) {
            return null;
        }

        $lines = [];

        foreach ($original->lines as $line) {
            if ($line->debit > 0) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'credit' => round((float) $line->debit, 2),
                    'description' => 'Reverse '.$original->description,
                ];
            }
            if ($line->credit > 0) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'debit' => round((float) $line->credit, 2),
                    'description' => 'Reverse '.$original->description,
                ];
            }
        }

        if (empty($lines)) {
            return null;
        }

        $reversalType = 'reversal_'.($original->reference_type ?? 'journal_entry');

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => 'Reversal - '.$original->description,
            'reference_type' => $reversalType,
            'reference_id' => $original->reference_id,
            'user_id' => $userId,
            'metadata' => array_merge($original->metadata ?? [], [
                'reverses' => [
                    'journal_entry_id' => $original->id,
                    'reference_type' => $original->reference_type,
                    'reference_id' => $original->reference_id,
                ],
            ]),
        ], $lines);
    }

    /**
     * Sales: replace an already-posted sale entry (used when items change after
     * an invoice has been recognized). Reverses the original entry exactly and
     * posts a fresh one against the same invoice reference. The fresh entry is
     * posted directly because postSaleEntry() is deduped on (sale, invoice_id)
     * and the original must stay next to its void_sale reversal for the trail.
     */
    public function repostSaleEntry(string $businessId, Invoice $invoice, ?int $userId = null): ?JournalEntry
    {
        $original = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('reference_type', 'sale')
            ->where('reference_id', $invoice->id)
            ->with('lines')
            ->first();

        if ($original) {
            $lines = [];
            foreach ($original->lines as $line) {
                if ($line->debit > 0) {
                    $lines[] = ['account_id' => $line->account_id, 'credit' => $line->debit, 'description' => 'COGS adjustment'];
                }
                if ($line->credit > 0) {
                    $lines[] = ['account_id' => $line->account_id, 'debit' => $line->credit, 'description' => 'COGS adjustment'];
                }
            }
            $this->post($businessId, [
                'date' => now()->toDateString(),
                'description' => 'Sale reversal (repost) - '.$invoice->invoice_number,
                'reference_type' => 'void_sale',
                'reference_id' => $invoice->id,
                'user_id' => $userId,
                'metadata' => ['invoice_id' => $invoice->id, 'repost' => true],
            ], $lines);
        }

        $meta = $this->saleEntryMeta($invoice, $userId);
        $meta['description'] = 'Sale (reposted) - '.$invoice->invoice_number;

        return $this->post($businessId, $meta, $this->saleEntryLines($invoice));
    }

    /**
     * Purchases: goods receipt → Dr 1030 (total cost).
     * The credit leg follows the payment method:
     *  - credit (default) → Cr 2010 (Accounts Payable)
     *  - cash → Cr 1010/1005 (drawer when an open shift exists, else safe)
     *  - bank/card/other → Cr 1020
     * When $payNowAmount is provided with a paid method, the cash/bank leg is
     * split: $payNowAmount goes to the cash/bank account and the remainder
     * (PO advances already recorded) to 2010 — so the supplier ledger still
     * balances to zero when the net due is paid at the door.
     * $referenceId is the purchase order id when available, otherwise the first
     * created batch id. $metadata carries the received batch/purchase order ids
     * for auditability.
     */
    public function postGoodsReceiptEntry(string $businessId, float $totalCost, int|string $referenceId, ?int $userId = null, string $description = 'Goods received', array $metadata = [], string $paymentMethod = 'credit', float $payNowAmount = 0): ?JournalEntry
    {
        $totalCost = round($totalCost, 2);
        if ($totalCost <= 0 || $referenceId === null) {
            return null;
        }

        $paid = in_array($paymentMethod, ['cash', 'bank', 'card', 'bank_transfer', 'check', 'mobile'], true)
            ? ($payNowAmount > 0 ? round($payNowAmount, 2) : $totalCost)
            : 0.0;

        // A paid-but-zero cash-out is a payable: fall back to the credit leg.
        $method = $paid > 0.005 ? $paymentMethod : 'credit';

        if ($paid > 0.005 && $paid < $totalCost) {
            $cashCode = $method === 'cash'
                ? $this->cashAccountCode('cash', $businessId, $userId)
                : '1020';
            $lines = [
                ['code' => '1030', 'debit' => $totalCost, 'description' => 'Goods received'],
                ['code' => $cashCode, 'credit' => $paid, 'description' => 'Goods received (paid now)'],
                ['code' => '2010', 'credit' => round($totalCost - $paid, 2), 'description' => 'Goods received (payable)'],
            ];
        } else {
            $creditCode = match ($method) {
                'cash' => $this->cashAccountCode('cash', $businessId, $userId),
                'bank', 'card', 'bank_transfer', 'check', 'mobile' => '1020',
                default => '2010',
            };
            $lines = [
                ['code' => '1030', 'debit' => $totalCost, 'description' => 'Goods received'],
                ['code' => $creditCode, 'credit' => $totalCost, 'description' => 'Goods received'],
            ];
        }

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => $description,
            'reference_type' => 'goods_receipt',
            'reference_id' => $referenceId,
            'user_id' => $userId,
            'metadata' => $metadata,
        ], $lines);
    }

    /**
     * Purchases: supplier payment → Dr 2010, Cr 1005 (cash) or 1020 (bank/card).
     */
    public function postSupplierPaymentEntry(string $businessId, PurchaseOrderPayment $payment, ?int $userId = null): ?JournalEntry
    {
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $cashCode = $this->cashAccountCode($payment->method, $businessId);
        $date = $payment->created_at?->toDateString() ?? now()->toDateString();

        return $this->postIfNeeded($businessId, [
            'date' => $date,
            'description' => 'Supplier payment - '.($payment->reference_number ?? 'payment #'.$payment->id),
            'reference_type' => 'supplier_payment',
            'reference_id' => $payment->id,
            'user_id' => $userId ?? $payment->user_id,
        ], [
            ['code' => '2010', 'debit' => $amount, 'description' => 'Supplier payment '.($payment->reference_number ?? $payment->id)],
            ['code' => $cashCode, 'credit' => $amount, 'description' => 'Supplier payment '.($payment->reference_number ?? $payment->id)],
        ]);
    }

    /**
     * Shifts: opening float → Dr 1010 (drawer), Cr 1005 (main safe).
     * $amount is the float actually transferred from the safe (opening float
     * minus any amount already carried over from the previous shift).
     */
    public function postShiftOpenEntry(string $businessId, Shift $shift, float $amount, ?int $userId = null): ?JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $date = $shift->started_at?->toDateString() ?? now()->toDateString();

        return $this->postIfNeeded($businessId, [
            'date' => $date,
            'description' => 'Shift opening float - '.($shift->shift_number ?? 'shift #'.$shift->id),
            'reference_type' => 'shift',
            'reference_id' => $shift->id,
            'shift_id' => $shift->id,
            'user_id' => $userId ?? $shift->user_id,
        ], [
            ['code' => '1010', 'debit' => $amount, 'description' => 'Opening float '.($shift->shift_number ?? $shift->id)],
            ['code' => '1005', 'credit' => $amount, 'description' => 'Opening float '.($shift->shift_number ?? $shift->id)],
        ]);
    }

    /**
     * Shifts: close reconciliation. No entry on a perfect match.
     * Shortage → Dr 5042 (Cash Shortage Expense), Cr 1010.
     * Over/surplus → Dr 1010, Cr 4040 (Cash Surplus Revenue).
     */
    public function postShiftCloseEntry(string $businessId, Shift $shift, ?int $userId = null): ?JournalEntry
    {
        $variance = round((float) $shift->variance, 2);
        if (abs($variance) <= 0.001) {
            return null;
        }

        $amount = abs($variance);
        $date = $shift->ended_at?->toDateString() ?? now()->toDateString();

        $lines = $variance < 0
            ? [
                ['code' => '5042', 'debit' => $amount, 'description' => 'Cash shortage '.($shift->shift_number ?? $shift->id)],
                ['code' => '1010', 'credit' => $amount, 'description' => 'Cash shortage '.($shift->shift_number ?? $shift->id)],
            ]
            : [
                ['code' => '1010', 'debit' => $amount, 'description' => 'Cash surplus '.($shift->shift_number ?? $shift->id)],
                ['code' => '4040', 'credit' => $amount, 'description' => 'Cash surplus '.($shift->shift_number ?? $shift->id)],
            ];

        return $this->postIfNeeded($businessId, [
            'date' => $date,
            'description' => 'Shift reconciliation - '.($shift->shift_number ?? 'shift #'.$shift->id),
            'reference_type' => 'shift_close',
            'reference_id' => $shift->id,
            'shift_id' => $shift->id,
            'user_id' => $userId ?? $shift->user_id,
        ], $lines);
    }

    /**
     * Inventory adjustments (type-based routing):
     *  - waste / damage            → Dr 5020 (Waste/Damage Expense), Cr 1030 (Inventory)
     *  - count_deficit             → Dr 5030 (Inventory Adjustment Loss), Cr 1030
     *  - count_surplus             → Dr 1030, Cr 4030 (Inventory Adjustment Revenue)
     *  - return                    → Dr 1030, Cr 5010 (reverses the sale COGS)
     *  - purchase_return           → Dr 2010 (Accounts Payable), Cr 1030 (debit note)
     *  - received                  → no entry here; covered by the goods-receipt entry (R1)
     */
    public function postInventoryAdjustmentEntry(string $businessId, InventoryAdjustment $adjustment, float $amount, ?int $userId = null): ?JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $meta = [
            'date' => $adjustment->created_at->toDateString(),
            'description' => 'Inventory adjustment - '.$adjustment->adjustment_number,
            'reference_type' => 'inventory_adjustment',
            'reference_id' => $adjustment->id,
            'user_id' => $userId ?? $adjustment->user_id,
            'metadata' => [
                'adjustment_id' => $adjustment->id,
                'batch_id' => $adjustment->batch_id,
                'type' => $adjustment->type,
                'liability_type' => $adjustment->liability_type,
                'quantity_adjusted' => (float) $adjustment->quantity_adjusted,
                'unit_cost' => (float) $adjustment->unit_cost,
                'supplier_id' => $adjustment->supplier_id ?? $adjustment->metadata['supplier_id'] ?? null,
                'purchase_order_id' => $adjustment->metadata['purchase_order_id'] ?? null,
                'responsibility' => $adjustment->metadata['responsibility'] ?? ($adjustment->liability_type === 'supplier_claim' ? 'supplier' : 'store'),
            ],
        ];

        $isSupplierLiability = $adjustment->liability_type === 'supplier_claim'
            || ($adjustment->metadata['responsibility'] ?? null) === 'supplier'
            || $adjustment->type === 'purchase_return';

        $lines = match (true) {
            $isSupplierLiability && $adjustment->type === 'waste' => [
                ['code' => '2010', 'debit' => $amount, 'description' => 'Supplier claim (waste) '.$adjustment->adjustment_number],
                ['code' => '1030', 'credit' => $amount, 'description' => 'Supplier claim (waste) '.$adjustment->adjustment_number],
            ],
            $isSupplierLiability && $adjustment->type === 'damage' => [
                ['code' => '2010', 'debit' => $amount, 'description' => 'Supplier claim (damage) '.$adjustment->adjustment_number],
                ['code' => '1030', 'credit' => $amount, 'description' => 'Supplier claim (damage) '.$adjustment->adjustment_number],
            ],
            $adjustment->type === 'purchase_return' => [
                ['code' => '2010', 'debit' => $amount, 'description' => 'Purchase return '.$adjustment->adjustment_number],
                ['code' => '1030', 'credit' => $amount, 'description' => 'Purchase return '.$adjustment->adjustment_number],
            ],
            $adjustment->type === 'waste', $adjustment->type === 'damage' => [
                ['code' => '5020', 'debit' => $amount, 'description' => $adjustment->adjustment_number],
                ['code' => '1030', 'credit' => $amount, 'description' => $adjustment->adjustment_number],
            ],
            $adjustment->type === 'count_surplus' => [
                ['code' => '1030', 'debit' => $amount, 'description' => $adjustment->adjustment_number],
                ['code' => '4030', 'credit' => $amount, 'description' => $adjustment->adjustment_number],
            ],
            $adjustment->type === 'return' => [
                ['code' => '1030', 'debit' => $amount, 'description' => $adjustment->adjustment_number],
                ['code' => '5010', 'credit' => $amount, 'description' => $adjustment->adjustment_number],
            ],
            $adjustment->type === 'received' => null,
            default => [ // count_deficit and any other deduction
                ['code' => '5030', 'debit' => $amount, 'description' => $adjustment->adjustment_number],
                ['code' => '1030', 'credit' => $amount, 'description' => $adjustment->adjustment_number],
            ],
        };

        if ($lines === null) {
            return null;
        }

        return $this->postIfNeeded($businessId, $meta, $lines);
    }

    /**
     * Sales returns: reverse revenue + tax and credit the refund side.
     * Revenue/tax reversal → Dr 4010 (revenue) + Dr 2020 (tax), Cr the refund
     * account (1040 receivable for credit returns, 1010/1005 for cash refunds,
     * 1020 for bank/card). The inventory restock leg (Dr 1030 / Cr 5010) is
     * posted separately by postInventoryAdjustmentEntry('return').
     */
    public function postSalesReturnEntry(string $businessId, InventoryAdjustment $adjustment, float $revenue, float $tax, ?int $userId = null, string $refundMethod = 'credit'): ?JournalEntry
    {
        $revenue = round($revenue, 2);
        $tax = round($tax, 2);
        $net = round($revenue + $tax, 2);

        if ($net <= 0) {
            return null;
        }

        $refundCode = match ($refundMethod) {
            'cash' => $this->cashAccountCode('cash', $businessId, $userId ?? $adjustment->user_id),
            'bank', 'card', 'bank_transfer', 'check', 'mobile' => '1020',
            default => '1040',
        };

        $lines = [
            ['code' => '4010', 'debit' => $revenue, 'description' => 'Sales return '.$adjustment->adjustment_number],
        ];
        if ($tax > 0) {
            $lines[] = ['code' => '2020', 'debit' => $tax, 'description' => 'Sales return '.$adjustment->adjustment_number];
        }
        $lines[] = ['code' => $refundCode, 'credit' => $net, 'description' => 'Sales return '.$adjustment->adjustment_number];

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => 'Sales return - '.$adjustment->adjustment_number,
            'reference_type' => 'sales_return',
            'reference_id' => $adjustment->id,
            'user_id' => $userId ?? $adjustment->user_id,
            'metadata' => array_merge($adjustment->metadata ?? [], [
                'adjustment_id' => $adjustment->id,
                'product_id' => $adjustment->product_id,
                'batch_id' => $adjustment->batch_id,
                'quantity' => abs((float) $adjustment->quantity_adjusted),
                'revenue' => $revenue,
                'tax' => $tax,
                'refund_method' => $refundMethod,
            ]),
        ], $lines);
    }

    /**
     * Exchanges: record the trade-in credit applied to the new (exchange)
     * invoice. The returned goods reverse the old receivable (Cr 1040 via
     * postSalesReturnEntry); this entry absorbs that credit onto the new
     * invoice's receivable (Dr 1040 / Cr 1040) so the new invoice reads as
     * fully paid once the price-difference payment is added.
     */
    public function postExchangeCreditEntry(string $businessId, Payment $payment, ?int $userId = null): ?JournalEntry
    {
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => 'Exchange credit - '.($payment->payment_number ?? 'payment #'.$payment->id),
            'reference_type' => 'exchange_credit',
            'reference_id' => $payment->id,
            'shift_id' => $payment->shift_id,
            'user_id' => $userId ?? $payment->user_id,
            'metadata' => [
                'invoice_id' => $payment->invoice_id,
                'trade_in_amount' => $amount,
            ],
        ], [
            ['code' => '1040', 'debit' => $amount, 'description' => 'Trade-in credit '.($payment->payment_number ?? $payment->id)],
            ['code' => '1040', 'credit' => $amount, 'description' => 'Trade-in credit '.($payment->payment_number ?? $payment->id)],
        ]);
    }

    /**
     * Exchanges: when the returned goods are worth more than the exchanged
     * ones, refund the surplus back to the customer (Dr 1040 / Cr cash-or-bank,
     * using the shift-aware drawer for cash).
     */
    public function postExchangeSurplusEntry(string $businessId, ReturnExchange $returnExchange, float $amount, ?int $userId = null): ?JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $method = $returnExchange->refund_method ?? 'cash';
        $cashCode = match ($method) {
            'cash' => $this->cashAccountCode('cash', $businessId, $userId ?? $returnExchange->user_id),
            'bank', 'card', 'bank_transfer', 'check', 'mobile' => '1020',
            default => '1040',
        };

        return $this->postIfNeeded($businessId, [
            'date' => now()->toDateString(),
            'description' => 'Exchange surplus refund - '.$returnExchange->return_number,
            'reference_type' => 'exchange_surplus',
            'reference_id' => $returnExchange->id,
            'user_id' => $userId ?? $returnExchange->user_id,
            'metadata' => [
                'return_exchange_id' => $returnExchange->id,
                'invoice_id' => $returnExchange->invoice_id,
                'refund_method' => $method,
            ],
        ], [
            ['code' => '1040', 'debit' => $amount, 'description' => 'Exchange surplus '.$returnExchange->return_number],
            ['code' => $cashCode, 'credit' => $amount, 'description' => 'Exchange surplus '.$returnExchange->return_number],
        ]);
    }

    public function hasEntry(string $businessId, string $referenceType, int|string|null $referenceId): bool
    {
        return $this->entryExists($businessId, $referenceType, $referenceId);
    }

    /**
     * COGS for an invoice, batch-exact (FEFO): Σ of the cost per unit actually
     * deducted from stock for each item. Deductions are recorded on
     * invoice_items.metadata['deductions'] at stock-deduction time
     * ([['batch_id'=>…,'unit_cost'=>…,'quantity'=>…]]). When deduction metadata
     * is missing (e.g. invoices created before the feature), falls back to the
     * product cost × quantity.
     */
    private function invoiceCogs(Invoice $invoice): float
    {
        if (! $invoice->relationLoaded('items')) {
            $invoice->load('items');
        }

        $cogs = 0.0;
        foreach ($invoice->items as $item) {
            $deductions = (array) ($item->metadata['deductions'] ?? []);
            if (! empty($deductions)) {
                foreach ($deductions as $deduction) {
                    $cogs += (float) ($deduction['unit_cost'] ?? 0) * (float) ($deduction['quantity'] ?? 0);
                }

                continue;
            }

            if (empty($item->product_id)) {
                continue;
            }
            $product = Product::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $invoice->business_id)
                ->find($item->product_id);
            if ($product) {
                $cogs += (float) $product->cost * (float) $item->quantity;
            }
        }

        return round($cogs, 2);
    }

    private function cashAccountCode(string $method, string $businessId, ?int $userId = null, ?int $shiftId = null): string
    {
        if ($method === 'cash') {
            $hasOpenShift = $shiftId !== null || ($userId !== null && Shift::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $businessId)
                ->where('user_id', $userId)
                ->where('status', 'open')
                ->exists());

            return $hasOpenShift ? '1010' : '1005';
        }

        return '1020';
    }

    /**
     * Resolve the cash-side account for a payment reversal so it matches the
     * account used on the original payment entry (drawer 1010 for POS cash,
     * safe 1005 for manual cash, or the bank account 1020 for card/bank).
     */
    private function paymentCashAccount(string $businessId, Payment $payment): string
    {
        $original = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('debit', '>', 0)
            ->whereHas('journalEntry', function ($q) use ($businessId, $payment) {
                $q->withoutGlobalScope(BusinessScope::class)
                    ->where('business_id', $businessId)
                    ->where('reference_type', 'invoice_payment')
                    ->where('reference_id', $payment->id);
            })
            ->with('account:id,code')
            ->first();

        if ($original && $original->account) {
            return $original->account->code;
        }

        return $this->cashAccountCode($payment->method, $businessId, $payment->user_id, $payment->shift_id);
    }

    private function isInClosedFiscalYear(string $businessId, string $date): bool
    {
        return FiscalYear::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('is_closed', true)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    private function entryExists(string $businessId, string $referenceType, int|string|null $referenceId): bool
    {
        if ($referenceId === null || $referenceId === '') {
            return false;
        }

        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->exists();
    }

    private function postIfNeeded(string $businessId, array $meta, array $lines): ?JournalEntry
    {
        $date = $meta['date'] ?? now()->toDateString();

        if ($this->isInClosedFiscalYear($businessId, $date)) {
            Log::warning('Skipped automated journal entry in a closed fiscal year.', [
                'business_id' => $businessId,
                'date' => $date,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
            ]);

            return null;
        }

        if ($this->entryExists($businessId, $meta['reference_type'] ?? '', $meta['reference_id'] ?? null)) {
            return null;
        }

        $this->ensureChartOfAccounts($businessId);

        return $this->post($businessId, $meta, $lines);
    }
}
