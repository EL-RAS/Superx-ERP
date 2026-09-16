<?php

use App\Models\Account;
use App\Scopes\BusinessScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full POS ↔ Accounting linkage:
     *  1. journal_entries gets a shift_id FK so every automated entry is
     *     traceable to the shift that produced it.
     *  2. Aligns the expense chart with the reconciliation spec: 5030 becomes
     *     "Cash Shortage Expense" (was Rent Expense) and 5041 becomes
     *     "Rent Expense" (was Cash Shortage Expense). Both are balanced-zero
     *     and unreferenced by any journal lines at this point.
     *  3. Backfills shift_id on existing entries via their reference:
     *     sale → invoice.shift_id, invoice_payment → payment.shift_id,
     *     shift / shift_close → the shift itself.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('user_id')->index();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });

        foreach (Account::withoutGlobalScope(BusinessScope::class)->where('code', '5030')->get() as $account) {
            $account->update([
                'name' => 'Cash Shortage Expense',
                'metadata' => array_merge($account->metadata ?? [], ['name_ar' => 'مصروف العجز النقدي']),
            ]);
        }

        foreach (Account::withoutGlobalScope(BusinessScope::class)->where('code', '5041')->get() as $account) {
            $account->update([
                'name' => 'Rent Expense',
                'metadata' => array_merge($account->metadata ?? [], ['name_ar' => 'مصروف الإيجار']),
            ]);
        }

        $this->backfillShiftId();
    }

    private function backfillShiftId(): void
    {
        DB::table('journal_entries')
            ->whereNull('shift_id')
            ->where('reference_type', 'sale')
            ->update([
                'shift_id' => DB::raw('(SELECT shift_id FROM invoices WHERE invoices.id = journal_entries.reference_id)'),
            ]);

        DB::table('journal_entries')
            ->whereNull('shift_id')
            ->where('reference_type', 'invoice_payment')
            ->update([
                'shift_id' => DB::raw('(SELECT shift_id FROM payments WHERE payments.id = journal_entries.reference_id)'),
            ]);

        DB::table('journal_entries')
            ->whereNull('shift_id')
            ->whereIn('reference_type', ['shift', 'shift_close'])
            ->update([
                'shift_id' => DB::raw('CAST(reference_id AS BIGINT)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
