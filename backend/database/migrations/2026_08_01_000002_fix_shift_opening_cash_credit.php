<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shift openings are internal asset transfers (POS drawer vs main safe),
     * not owner capital contributions. Move the credit leg of any already
     * posted shift-opening entries from 3010 (Owner's Capital) to 1005
     * (Main Safe / General Cash) and recompute account balances.
     */
    public function up(): void
    {
        // Every shift-opening journal entry line that credited Owner's Capital.
        $affectedLines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
            ->join('accounts as a', 'l.account_id', '=', 'a.id')
            ->where('e.reference_type', 'shift')
            ->where('a.code', '3010')
            ->where('l.credit', '>', 0)
            ->select('l.id', 'l.business_id', 'l.journal_entry_id')
            ->get();

        if ($affectedLines->isEmpty()) {
            return;
        }

        $affectedBusinessIds = $affectedLines->pluck('business_id')->unique();

        foreach ($affectedBusinessIds as $businessId) {
            $assetsParentId = DB::table('accounts')
                ->where('business_id', $businessId)
                ->where('code', '1000')
                ->value('id');

            $safe = DB::table('accounts')
                ->where('business_id', $businessId)
                ->where('code', '1005')
                ->first();

            if (! $safe) {
                $safeId = DB::table('accounts')->insertGetId([
                    'business_id' => $businessId,
                    'code' => '1005',
                    'name' => 'Main Safe / General Cash',
                    'type' => 'asset',
                    'parent_id' => $assetsParentId,
                    'is_active' => true,
                    'metadata' => json_encode([
                        'name_ar' => 'الصندوق الرئيسي / الخزنة',
                        'is_system' => true,
                        'balance' => 0,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $safeId = $safe->id;
            }

            $lineIds = $affectedLines->where('business_id', $businessId)->pluck('id');

            DB::table('journal_entry_lines')
                ->whereIn('id', $lineIds)
                ->update([
                    'account_id' => $safeId,
                    'description' => 'Cash float transferred from main safe',
                ]);
        }

        // Recompute every account balance for each affected business so the
        // move from 3010 → 1005 is reflected (equity untouched, main safe reduced).
        foreach ($affectedBusinessIds as $businessId) {
            $accounts = DB::table('accounts')
                ->where('business_id', $businessId)
                ->get(['id', 'type', 'metadata']);

            foreach ($accounts as $account) {
                $totals = DB::table('journal_entry_lines as l')
                    ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
                    ->where('l.business_id', $businessId)
                    ->where('l.account_id', $account->id)
                    ->where('e.is_posted', true)
                    ->selectRaw('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
                    ->first();

                $debit = (float) $totals->debit;
                $credit = (float) $totals->credit;

                $balance = in_array($account->type, ['liability', 'equity', 'revenue'], true)
                    ? $credit - $debit
                    : $debit - $credit;

                $meta = $account->metadata ? json_decode($account->metadata, true) : [];
                $meta['balance'] = round($balance, 2);

                DB::table('accounts')
                    ->where('id', $account->id)
                    ->update(['metadata' => json_encode($meta)]);
            }
        }
    }

    public function down(): void
    {
    }
};
