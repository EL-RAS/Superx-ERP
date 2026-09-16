<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->foreignId('purchase_order_id')->nullable()->change();
            $table->foreignId('goods_receipt_id')->nullable()->after('purchase_order_id')->constrained('goods_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_id']);
            $table->dropColumn('goods_receipt_id');
            $table->foreignId('purchase_order_id')->nullable(false)->change();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });
    }
};