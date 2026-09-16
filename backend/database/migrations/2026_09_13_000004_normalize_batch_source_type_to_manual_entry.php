<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_batches')
            ->where('source_type', 'manual_adjustment')
            ->update(['source_type' => 'manual_entry']);
    }

    public function down(): void
    {
        DB::table('product_batches')
            ->where('source_type', 'manual_entry')
            ->update(['source_type' => 'manual_adjustment']);
    }
};
