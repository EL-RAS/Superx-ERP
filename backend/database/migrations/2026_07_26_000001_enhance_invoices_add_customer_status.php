<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained('customers')->nullOnDelete();
            $table->string('status')->default('draft')->after('payment_status');
            $table->date('due_date')->nullable()->after('status');
            $table->string('currency', 3)->default('JOD')->after('due_date');
            $table->decimal('subtotal', 15, 4)->default(0)->after('currency');
            $table->decimal('shipping_amount', 15, 4)->default(0)->after('subtotal');
            $table->text('notes')->nullable()->after('shipping_amount');
            $table->jsonb('metadata')->nullable()->after('notes');
            $table->timestamp('sent_at')->nullable()->after('metadata');
            $table->timestamp('voided_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn([
                'customer_id', 'status', 'due_date', 'currency',
                'subtotal', 'shipping_amount', 'notes', 'metadata',
                'sent_at', 'voided_at',
            ]);
        });
    }
};
