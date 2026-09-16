<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->string('liability_type')->nullable()->default('internal_store_loss')->after('type');
            $table->foreignId('supplier_id')->nullable()->after('batch_id')->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_return_id')->nullable()->after('supplier_id')->constrained('inventory_adjustments')->nullOnDelete();
        });

        DB::table('inventory_adjustments')
            ->whereNull('liability_type')
            ->update([
                'liability_type' => DB::raw("CASE
                    WHEN COALESCE(metadata->>'responsibility', '') = 'supplier' OR type = 'purchase_return' THEN 'supplier_claim'
                    ELSE 'internal_store_loss'
                END"),
            ]);

        DB::table('inventory_adjustments')
            ->whereNull('supplier_id')
            ->whereRaw("NULLIF(metadata->>'supplier_id', '') IS NOT NULL")
            ->update(['supplier_id' => DB::raw("NULLIF(metadata->>'supplier_id', '')::bigint")]);
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_return_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('liability_type');
        });
    }
};
