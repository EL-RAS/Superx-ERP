<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->integer('buy_quantity')->nullable()->after('min_amount');
            $table->integer('get_quantity')->nullable()->after('buy_quantity');
            $table->decimal('discount_value', 15, 4)->nullable()->after('get_quantity');
            $table->integer('max_uses')->nullable()->after('discount_value');
            $table->integer('current_uses')->default(0)->after('max_uses');
            $table->jsonb('combo_products')->nullable()->after('current_uses');
            $table->time('happy_hour_start')->nullable()->after('combo_products');
            $table->time('happy_hour_end')->nullable()->after('happy_hour_start');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn([
                'buy_quantity', 'get_quantity', 'discount_value', 'max_uses', 'current_uses',
                'combo_products', 'happy_hour_start', 'happy_hour_end',
            ]);
        });
    }
};
