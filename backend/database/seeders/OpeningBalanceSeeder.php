<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Scopes\BusinessScope;
use App\Services\AccountingService;
use App\Services\InventorySyncService;
use Illuminate\Database\Seeder;

/**
 * Post opening-balance journal entries so the fresh-seed Chart of Accounts
 * reflects ONLY real seeded records — never hardcoded dummy balances.
 *
 * Per business it posts one balanced entry (reference_type = opening_balance)
 * built dynamically from seeded data:
 *   - Dr 1030 Inventory Asset  = Σ remaining active non-expired batch qty × unit cost
 *                                (plus simple-product stock_quantity × cost) — 0 when none
 *   - Dr 1005 Main Safe / Cash = Σ explicit seeded cash deposits (cash payments flagged
 *                                metadata.opening_balance_deposit = true) — 0 when none
 *   - Cr 2010 Accounts Payable = Σ unpaid Purchase Orders (non-draft/non-cancelled,
 *                                total − completed payments) — 0 when none
 *   - Cr 3010 Owner's Capital  = balancing leg (Dr when the opening position is a deficit)
 *
 * Businesses with no seeded balances at all are skipped entirely. Idempotent:
 * businesses that already carry an opening_balance entry are skipped, so
 * `db:seed` may be re-run safely.
 */
class OpeningBalanceSeeder extends Seeder
{
    public function run(): void
    {
        $accounting = app(AccountingService::class);
        $inventorySync = app(InventorySyncService::class);

        foreach (Business::all() as $business) {
            $already = JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $business->id)
                ->where('reference_type', 'opening_balance')
                ->exists();

            if ($already) {
                $this->command?->info("{$business->name}: opening balance already posted — skipping.");

                continue;
            }

            $stockValue = $inventorySync->stockValue($business->id);
            $payables = $this->unpaidPurchaseOrders($business->id);
            $cashDeposits = $this->explicitCashDeposits($business->id);
            $equity = round($stockValue + $cashDeposits - $payables, 2);

            $lines = [];

            if ($stockValue > 0.005) {
                $lines[] = [
                    'code' => '1030',
                    'debit' => $stockValue,
                    'description' => 'Opening inventory stock value',
                ];
            }

            if ($cashDeposits > 0.005) {
                $lines[] = [
                    'code' => '1005',
                    'debit' => $cashDeposits,
                    'description' => 'Opening cash deposits',
                ];
            }

            if ($payables > 0.005) {
                $lines[] = [
                    'code' => '2010',
                    'credit' => $payables,
                    'description' => 'Opening supplier payable balances',
                ];
            }

            if ($lines === []) {
                $this->command?->info("{$business->name}: no seeded balances to post — skipping.");

                continue;
            }

            if ($equity > 0.005) {
                $lines[] = [
                    'code' => '3010',
                    'credit' => $equity,
                    'description' => 'Owner capital (opening balance)',
                ];
            } elseif ($equity < -0.005) {
                $lines[] = [
                    'code' => '3010',
                    'debit' => abs($equity),
                    'description' => 'Owner capital draw / opening deficit',
                ];
            }

            $entry = $accounting->post($business->id, [
                'date' => now()->toDateString(),
                'description' => 'Opening balance - business setup',
                'reference_type' => 'opening_balance',
                'reference_id' => 0,
                'entry_prefix' => 'OPEN',
                'metadata' => [
                    'type' => 'opening_balance',
                    'stock_value' => $stockValue,
                    'opening_cash' => $cashDeposits,
                    'opening_payables' => $payables,
                ],
            ], $lines);

            $this->command?->info(
                "{$business->name}: posted opening balance (entry #{$entry->id}) — inventory {$stockValue}, cash {$cashDeposits}, payables {$payables}"
            );
        }
    }

    /**
     * Opening supplier debt = Σ unpaid non-draft/non-cancelled purchase orders
     * (total − completed payments). 0 when no purchase orders are seeded.
     */
    private function unpaidPurchaseOrders(string $businessId): float
    {
        return round(
            PurchaseOrder::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $businessId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->with(['payments' => fn ($q) => $q->where('status', 'completed')])
                ->get()
                ->sum(fn (PurchaseOrder $po) => round((float) $po->total_amount - $po->paid_amount, 2)),
            2
        );
    }

    /**
     * Explicit seeded cash deposits = Σ cash payments flagged with
     * metadata.opening_balance_deposit = true. 0 when none are seeded.
     */
    private function explicitCashDeposits(string $businessId): float
    {
        return round(
            Payment::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $businessId)
                ->where('method', 'cash')
                ->whereRaw("COALESCE(metadata->>'opening_balance_deposit','') = 'true'")
                ->sum('amount'),
            2
        );
    }
}
