<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->integer('balance_after')->nullable()->after('points');
            $table->foreignId('invoice_id')->nullable()->after('balance_after')->constrained('invoices')->nullOnDelete();
            $table->date('points_expiry_date')->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropColumn(['balance_after', 'invoice_id', 'points_expiry_date']);
        });
    }
};
