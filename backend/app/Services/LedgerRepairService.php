<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Scopes\BusinessScope;

/**
 * Audits the inventory side of the general ledger for one business and posts
 * the corrections needed to bring it back in line with physical stock and the
 * frozen cost of every sale.
 *
 * Two things are checked, in this order (the second depends on the first):
 *
 *  1. COGS (5010). For every invoice that has a posted recognition entry the
 *     net amount already sitting on 5010 - across the `sale` entry, its
 *     `void_sale` / `reversal_sale` mirrors and any earlier repair - is
 *     compared with the invoice's true cost (invoiceCogs(): the frozen
 *     deductions recorded when stock left the shelf). A drift becomes a
 *     single balanced `cogs_repair` entry (Dr/Cr 5010 <-> 1030) so the audit
 *     trail of the original posting is preserved and a second run finds no
 *     drift to correct.
 *
 *  2. Inventory Asset (1030). InventorySyncService::sync() recomputes the
 *     account against the real on-hand value (batch stock at exact batch
 *     cost, simple stock at its goods-receipt cost layers) and posts a
 *     self-correcting 1030 <-> 3010 delta for whatever is left.
 *
 * Nothing is written in audit mode: both steps run in dry-run so the caller
 * can report what a repair would do before committing to it.
 */
class LedgerRepairService
{
    /**
     * Entry types whose 5010 legs belong to an invoice's cost of goods sold.
     * All of them carry the invoice id as their reference_id, so they net out
     * against each other per invoice.
     */
    public const COGS_REFERENCE_TYPES = ['sale', 'void_sale', 'reversal_sale', 'cogs_repair'];

    public function __construct(
        private AccountingService $accounting,
        private InventorySyncService $sync,
        private InventoryValuationService $valuation,
    ) {}

    /**
     * Report what a repair would change, without touching the ledger.
     *
     * @return array<string, mixed>
     */
    public function audit(string $businessId): array
    {
        return [
            'business_id' => $businessId,
            'business_name' => $this->businessName($businessId),
            'cogs' => $this->auditCogs($businessId),
            'inventory' => $this->sync->sync($businessId, null, true),
            'stock_value' => $this->valuation->stockValue($businessId),
        ];
    }

    /**
     * Post every COGS correction, then revalue Inventory Asset against the
     * real on-hand stock value.
     *
     * @return array<string, mixed>
     */
    public function repair(string $businessId, ?int $userId = null): array
    {
        $cogs = $this->repairCogs($businessId, $userId);
        $inventory = $this->sync->sync($businessId, $userId, false);

        return [
            'business_id' => $businessId,
            'business_name' => $this->businessName($businessId),
            'cogs' => $cogs,
            'inventory' => $inventory,
            'stock_value' => $this->valuation->stockValue($businessId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditCogs(string $businessId): array
    {
        $rows = [];
        $driftCount = 0;
        $driftTotal = 0.0;

        foreach ($this->netPostedCogs($businessId) as $invoiceId => $posted) {
            $invoice = $this->invoice($businessId, (int) $invoiceId);
            if (! $invoice) {
                $rows[] = [
                    'invoice_id' => (int) $invoiceId,
                    'invoice_number' => null,
                    'posted' => $posted,
                    'should_be' => null,
                    'delta' => 0.0,
                    'status' => 'orphaned',
                ];

                continue;
            }

            $correct = $this->correctCogs($invoice);
            $delta = round($correct - $posted, 2);

            if (abs($delta) > 0.005) {
                $driftCount++;
                $driftTotal = round($driftTotal + $delta, 2);
            }

            $rows[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'posted' => $posted,
                'should_be' => $correct,
                'delta' => $delta,
                'status' => abs($delta) > 0.005 ? 'drifted' : 'ok',
            ];
        }

        return [
            'invoices_audited' => count($rows),
            'invoices_with_drift' => $driftCount,
            'net_drift' => $driftTotal,
            'invoices' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repairCogs(string $businessId, ?int $userId): array
    {
        $audit = $this->auditCogs($businessId);
        $corrections = [];

        foreach ($audit['invoices'] as $row) {
            if ($row['status'] !== 'drifted') {
                continue;
            }

            $invoice = $this->invoice($businessId, $row['invoice_id']);
            if (! $invoice) {
                continue;
            }

            $entry = $this->postCogsCorrection($businessId, $invoice, $row['posted'], $row['should_be'], $row['delta'], $userId);
            $corrections[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'posted' => $row['posted'],
                'should_be' => $row['should_be'],
                'delta' => $row['delta'],
                'entry_id' => $entry?->id,
            ];
        }

        $audit['invoices_with_drift'] = count($corrections);
        $audit['corrections'] = $corrections;

        return $audit;
    }

    /**
     * Net COGS already on the ledger per invoice: every posted 5010 debit
     * minus credit across the invoice's recognition entry and its mirrors.
     *
     * Discovery starts from the entries themselves, not from their 5010
     * lines, so an invoice whose cost legs are missing entirely still shows up
     * with a posted value of 0 - that is exactly the drift the repair exists
     * to correct.
     *
     * @return array<int, float>
     */
    private function netPostedCogs(string $businessId): array
    {
        $entries = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->whereIn('reference_type', self::COGS_REFERENCE_TYPES)
            ->where('is_posted', true)
            ->whereNotNull('reference_id')
            ->get(['id', 'reference_id']);

        if ($entries->isEmpty()) {
            return [];
        }

        $netByInvoice = [];
        foreach ($entries as $entry) {
            $netByInvoice[(int) $entry->reference_id] = 0.0;
        }

        $account = $this->accounting->accountByCode($businessId, '5010');
        if (! $account) {
            return $netByInvoice;
        }

        $referenceById = $entries->keyBy('id');

        JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('account_id', $account->id)
            ->whereIn('journal_entry_id', $entries->pluck('id')->all())
            ->get(['journal_entry_id', 'debit', 'credit'])
            ->each(function (JournalEntryLine $line) use ($referenceById, &$netByInvoice) {
                $invoiceId = (int) $referenceById->get($line->journal_entry_id)?->reference_id;
                $netByInvoice[$invoiceId] = round(
                    ($netByInvoice[$invoiceId] ?? 0.0) + (float) $line->debit - (float) $line->credit,
                    2,
                );
            });

        return $netByInvoice;
    }

    /**
     * What 5010 should say for this invoice right now. A voided invoice has
     * no stock left the door for, so its correct cost is zero.
     */
    private function correctCogs(Invoice $invoice): float
    {
        if ($invoice->status === 'void') {
            return 0.0;
        }

        return $this->accounting->invoiceCogs($invoice);
    }

    /**
     * One balanced correcting entry per drifted invoice. Positive delta (the
     * ledger understated cost) debits 5010 and credits 1030; negative reverses.
     *
     * @return JournalEntry|null
     */
    private function postCogsCorrection(string $businessId, Invoice $invoice, float $posted, float $shouldBe, float $delta, ?int $userId)
    {
        $amount = round(abs($delta), 2);
        if ($amount <= 0.005) {
            return null;
        }

        $increase = $delta > 0;

        return $this->accounting->post($businessId, [
            'date' => now()->toDateString(),
            'description' => 'COGS correction - invoice '.$invoice->invoice_number,
            'reference_type' => 'cogs_repair',
            'reference_id' => $invoice->id,
            'user_id' => $userId,
            'entry_prefix' => 'AUTO',
            'metadata' => [
                'invoice_id' => $invoice->id,
                'was_posted' => $posted,
                'should_be' => $shouldBe,
                'delta' => $delta,
            ],
        ], [
            [
                'code' => '5010',
                'debit' => $increase ? $amount : 0,
                'credit' => $increase ? 0 : $amount,
                'description' => 'COGS correction - invoice '.$invoice->invoice_number,
            ],
            [
                'code' => '1030',
                'debit' => $increase ? 0 : $amount,
                'credit' => $increase ? $amount : 0,
                'description' => 'Inventory asset correction - invoice '.$invoice->invoice_number,
            ],
        ]);
    }

    private function invoice(string $businessId, int $invoiceId): ?Invoice
    {
        return Invoice::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->find($invoiceId);
    }

    private function businessName(string $businessId): ?string
    {
        return Business::withoutGlobalScope(BusinessScope::class)
            ->where('id', $businessId)
            ->value('name');
    }
}
