<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('tier_level');
        });

        DB::table('customers')
            ->whereRaw("id IN (SELECT customer_id FROM invoices WHERE status NOT IN ('draft','void') GROUP BY customer_id HAVING SUM(net_amount) > 500)")
            ->update(['is_vip' => true]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_vip');
        });
    }
};
