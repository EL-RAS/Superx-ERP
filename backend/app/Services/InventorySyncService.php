<?php

namespace App\Services;

use App\Jobs\ReconcileInventoryAccounting;
use App\Models\JournalEntryLine;
use App\Scopes\BusinessScope;

class InventorySyncService
{
    /**
     * Queue a background self-healing reconciliation for a business. Called
     * after any flow that can move stock (bulk import, GRN, manual batch add)
     * so a drift between the 1030 ledger balance and the real on-hand valuation
     * is closed automatically without blocking the request that triggered it.
     */
    public function queueReconcile(string $businessId, ?int $userId = null): void
    {
        ReconcileInventoryAccounting::dispatch($businessId, $userId);
    }

    /**
     * Reconcile the 1030 Inventory Asset account with the actual on-hand stock
     * value for a business. Posts a balanced opening-balance entry for the
     * difference (Debit 1030 / Credit 3010 when stock value exceeds the posted
     * balance). Safe to run repeatedly: the delta math makes it self-correcting.
     *
     * @return array{ok: bool, changed: bool, stock_value: float, current_balance: float, delta: float, new_balance?: float, entry_id?: int, message: string}
     */
    /**
     * Total on-hand stock value for a business. Delegates to
     * InventoryValuationService so the figure the ledger is reconciled to is
     * the very same figure the stock-valuation report prints: batch stock at
     * exact batch cost, simple stock at the cost of the goods-receipt layers
     * that are actually still on the shelf.
     */
    public function stockValue(string $businessId): float
    {
        return app(InventoryValuationService::class)->stockValue($businessId);
    }

    public function sync(string $businessId, ?int $userId = null, bool $dryRun = false): array
    {
        $accounting = app(AccountingService::class);
        $accounting->ensureChartOfAccounts($businessId);

        $inventory = $accounting->accountByCode($businessId, '1030');
        $equity = $accounting->accountByCode($businessId, '3010');

        if (! $inventory || ! $equity) {
            return [
                'ok' => false,
                'changed' => false,
                'stock_value' => 0,
                'current_balance' => 0,
                'delta' => 0,
                'message' => 'Required accounts (1030 / 3010) are missing from the chart of accounts.',
            ];
        }

        $stockValue = $this->stockValue($businessId);

        $linesQuery = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('account_id', $inventory->id)
            ->whereHas('journalEntry', fn ($query) => $query
                ->withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $businessId)
                ->where('is_posted', true));

        $currentBalance = round((clone $linesQuery)->sum('debit') - (clone $linesQuery)->sum('credit'), 2);
        $delta = round($stockValue - $currentBalance, 2);

        if (abs($delta) <= 0.005) {
            return [
                'ok' => true,
                'changed' => false,
                'stock_value' => $stockValue,
                'current_balance' => $currentBalance,
                'delta' => 0,
                'message' => 'Inventory already in sync with accounting.',
            ];
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'changed' => false,
                'stock_value' => $stockValue,
                'current_balance' => $currentBalance,
                'delta' => $delta,
                'message' => 'Dry run: would post '.abs($delta).' adjustment to 1030 (debit) / 3010 (credit).',
            ];
        }

        $amount = abs($delta);

        if ($delta > 0) {
            $entry = $accounting->post($businessId, [
                'date' => now()->toDateString(),
                'description' => 'Initial Inventory Stock Valuation Sync',
                'reference_type' => 'inventory_opening',
                'reference_id' => 0,
                'user_id' => $userId,
                'entry_prefix' => 'AUTO',
            ], [
                [
                    'code' => '1030',
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Opening inventory stock value',
                ],
                [
                    'code' => '3010',
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Opening balance equity - inventory',
                ],
            ]);
        } else {
            $entry = $accounting->post($businessId, [
                'date' => now()->toDateString(),
                'description' => 'Inventory Stock Valuation Correction',
                'reference_type' => 'inventory_opening',
                'reference_id' => 0,
                'user_id' => $userId,
                'entry_prefix' => 'AUTO',
            ], [
                [
                    'code' => '3010',
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Opening balance equity reversal - inventory',
                ],
                [
                    'code' => '1030',
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Inventory stock value correction',
                ],
            ]);
        }

        return [
            'ok' => true,
            'changed' => true,
            'stock_value' => $stockValue,
            'current_balance' => $currentBalance,
            'delta' => $delta,
            'new_balance' => $stockValue,
            'entry_id' => $entry->id,
            'message' => 'Posted inventory valuation entry #'.$entry->id,
        ];
    }
}
