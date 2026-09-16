<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('purchase_order_id')->constrained('suppliers')->nullOnDelete();
            $table->string('payment_method')->nullable()->after('user_id');
            $table->decimal('total_amount', 15, 2)->nullable()->after('payment_method');
            $table->string('status')->default('received')->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['payment_method', 'total_amount', 'status']);
        });
    }
};