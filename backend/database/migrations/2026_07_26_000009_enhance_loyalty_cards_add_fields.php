<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->decimal('total_points_earned', 15, 4)->default(0)->after('points_balance');
            $table->decimal('total_points_redeemed', 15, 4)->default(0)->after('total_points_earned');
            $table->decimal('total_spend', 15, 4)->default(0)->after('total_points_redeemed');
            $table->timestamp('tier_upgraded_at')->nullable()->after('total_spend');
            $table->jsonb('metadata')->nullable()->after('tier_upgraded_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropColumn(['total_points_earned', 'total_points_redeemed', 'total_spend', 'tier_upgraded_at', 'metadata']);
        });
    }
};
