<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit')->default('pcs')->after('barcode');
            $table->decimal('tax_rate', 5, 2)->default(16)->after('cost');
            $table->string('category')->nullable()->after('tax_rate');
            $table->boolean('has_expiry')->default(false)->after('category');
            $table->boolean('has_batch')->default(false)->after('has_expiry');
            $table->decimal('min_stock', 15, 4)->default(0)->after('has_batch');
            $table->decimal('reorder_level', 15, 4)->default(0)->after('min_stock');
            $table->decimal('stock_quantity', 15, 4)->default(0)->after('reorder_level');
            $table->string('storage_location')->nullable()->after('stock_quantity');
            $table->boolean('is_weighable')->default(false)->after('storage_location');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'unit', 'tax_rate', 'category', 'has_expiry', 'has_batch',
                'min_stock', 'reorder_level', 'stock_quantity', 'storage_location', 'is_weighable',
            ]);
        });
    }
};
