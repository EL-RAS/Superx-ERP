<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_sequences')->whereIn('kind', ['invoice', 'purchase_order', 'grn'])->delete();

        $this->backfillKind('invoice', 'invoices', 'invoice_number');
        $this->backfillKind('purchase_order', 'purchase_orders', 'order_number');
        $this->backfillKind('grn', 'goods_receipts', 'receipt_number');
    }

    public function down(): void
    {
        DB::table('document_sequences')->whereIn('kind', ['invoice', 'purchase_order', 'grn'])->delete();
    }

    /**
     * Backfill counter rows from the highest numeric suffix already used per
     * (business, digit-free prefix). Legacy random codes (e.g. INV-XXXXXXXX
     * with non-digit suffixes) are ignored, matching DocumentNumberService.
     */
    private function backfillKind(string $kind, string $table, string $column): void
    {
        $rows = DB::table($table)
            ->whereRaw("{$column} ~ '^[^0-9]*[0-9]+$'")
            ->selectRaw(
                "business_id, substring({$column} from '^([^0-9]*)[0-9]+$') AS prefix, ".
                "(regexp_match({$column}, '([0-9]+)$'))[1]::int AS suffix"
            )
            ->get()
            ->groupBy('business_id')
            ->map(function ($group) use ($kind) {
                return $group->groupBy('prefix')->map(fn ($rows) => [
                    'business_id' => $rows->first()->business_id,
                    'kind' => $kind,
                    'prefix' => $rows->first()->prefix,
                    'current' => $rows->max('suffix'),
                ]);
            })
            ->flatten(1)
            ->values()
            ->all();

        if (empty($rows)) {
            return;
        }

        DB::table('document_sequences')->insert($rows);
    }
};
