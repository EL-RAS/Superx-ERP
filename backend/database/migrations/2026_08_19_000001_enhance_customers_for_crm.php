<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('total_spend', 12, 2)->default(0)->after('tier_level');
            $table->unsignedInteger('total_visits')->default(0)->after('total_spend');
            $table->timestamp('last_visit_date')->nullable()->after('total_visits');
            $table->text('delivery_notes')->nullable()->after('address');
        });

        DB::table('customers')->whereNull('phone')->update(['phone' => null]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['total_spend', 'total_visits', 'last_visit_date', 'delivery_notes']);
        });
    }
};
