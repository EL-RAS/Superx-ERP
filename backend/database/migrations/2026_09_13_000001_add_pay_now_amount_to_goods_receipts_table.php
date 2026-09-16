<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->decimal('pay_now_amount', 15, 2)->nullable()->after('total_amount');
        });

        // Receipts paid in full at the door before this column existed carry
        // a payable leg of zero — record the cash-out so the leg-based GP
        // subledger query (total_amount − COALESCE(pay_now_amount, 0))
        // excludes them exactly like new rows.
        DB::table('goods_receipts')
            ->whereIn('payment_method', ['cash', 'card', 'bank_transfer', 'check', 'mobile'])
            ->whereNull('pay_now_amount')
            ->update(['pay_now_amount' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('pay_now_amount');
        });
    }
};