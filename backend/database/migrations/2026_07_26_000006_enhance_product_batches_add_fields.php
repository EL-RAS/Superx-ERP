<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->decimal('quantity_sold', 10, 2)->default(0)->after('quantity');
            $table->decimal('quantity_returned', 10, 2)->default(0)->after('quantity_sold');
            $table->foreignId('supplier_id')->nullable()->after('quantity_returned')->constrained('suppliers')->nullOnDelete();
            $table->date('received_date')->nullable()->after('supplier_id');
            $table->decimal('selling_price', 15, 4)->nullable()->after('cost_per_unit');
            $table->string('storage_location')->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn([
                'quantity_sold', 'quantity_returned', 'supplier_id', 'received_date', 'selling_price', 'storage_location',
            ]);
        });
    }
};
