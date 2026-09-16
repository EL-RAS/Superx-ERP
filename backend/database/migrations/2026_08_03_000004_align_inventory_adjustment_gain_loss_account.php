<?php

use App\Models\Account;
use App\Scopes\BusinessScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Inventory ↔ Accounting integration chart alignment:
     *  1. Repurposes 5030 from "Cash Shortage Expense" (shift reconciliation)
     *     to "Inventory Adjustment Gain / Loss" (stock count discrepancies).
     *  2. Creates 5042 "Cash Shortage Expense" for the shift reconciliation
     *     flows that previously used 5030.
     *  3. Moves any existing journal lines that referenced 5030 to 5042 and
     *     recomputes cached balances so the realignment stays balanced.
     */
    public function up(): void
    {
        foreach (Account::withoutGlobalScope(BusinessScope::class)->where('code', '5030')->get() as $account) {
            $account->update([
                'name' => 'Inventory Adjustment Gain / Loss',
                'metadata' => array_merge($account->metadata ?? [], ['name_ar' => 'إيراد/خسارة تسوية المخزون']),
            ]);
        }

        foreach (Account::withoutGlobalScope(BusinessScope::class)->where('code', '5030')->get() as $account) {
            $businessId = $account->business_id;

            $cashShortage = Account::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $businessId)
                ->where('code', '5042')
                ->first();

            if (! $cashShortage) {
                $parentId = Account::withoutGlobalScope(BusinessScope::class)
                    ->where('business_id', $businessId)
                    ->where('code', '5000')
                    ->value('id');

                $cashShortage = Account::create([
                    'business_id' => $businessId,
                    'code' => '5042',
                    'name' => 'Cash Shortage Expense',
                    'type' => 'expense',
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'metadata' => [
                        'name_ar' => 'مصروف العجز النقدي',
                        'is_system' => true,
                    ],
                ]);
            }

            DB::table('journal_entry_lines')
                ->where('business_id', $businessId)
                ->where('account_id', $account->id)
                ->update(['account_id' => $cashShortage->id]);
        }

        $this->recomputeBalances(['5030', '5042']);
    }

    private function recomputeBalances(array $codes): void
    {
        foreach (Account::withoutGlobalScope(BusinessScope::class)->whereIn('code', $codes)->get() as $account) {
            $query = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.account_id', $account->id)
                ->where('e.business_id', $account->business_id)
                ->where('e.is_posted', true);

            $debit = (float) (clone $query)->sum('l.debit');
            $credit = (float) (clone $query)->sum('l.credit');

            $balance = in_array($account->type, ['liability', 'equity', 'revenue'], true)
                ? $credit - $debit
                : $debit - $credit;

            $account->update([
                'metadata' => array_merge($account->metadata ?? [], ['balance' => $balance]),
            ]);
        }
    }

    public function down(): void
    {
        // Reverting the chart is destructive (historical entries reference 5042
        // or 5030 by id); keep this migration one-way.
    }
};
