<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shift reconciliation must be exact: every payment and invoice created
     * while a shift is open is stamped with that shift's id. This migration
     * adds the linkage and backfills existing rows by matching each record to
     * the same user's shift whose time window contains its created_at.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('invoice_id')->index();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('user_id')->index();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });

        $this->backfill('payments');
        $this->backfill('invoices');
    }

    private function backfill(string $table): void
    {
        $shifts = DB::table('shifts')
            ->orderBy('started_at')
            ->get(['id', 'business_id', 'user_id', 'started_at', 'ended_at']);

        $byBusinessUser = [];
        foreach ($shifts as $shift) {
            $byBusinessUser[$shift->business_id][$shift->user_id][] = $shift;
        }

        DB::table($table)
            ->whereNull('shift_id')
            ->select('id', 'business_id', 'user_id', 'created_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $byBusinessUser) {
                foreach ($rows as $row) {
                    $candidates = $byBusinessUser[$row->business_id][$row->user_id] ?? [];

                    $matches = array_values(array_filter($candidates, function ($shift) use ($row) {
                        $end = $shift->ended_at ?? now();

                        return $row->created_at >= $shift->started_at && $row->created_at <= $end;
                    }));

                    if (count($matches) === 1) {
                        DB::table($table)->where('id', $row->id)->update(['shift_id' => $matches[0]->id]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
