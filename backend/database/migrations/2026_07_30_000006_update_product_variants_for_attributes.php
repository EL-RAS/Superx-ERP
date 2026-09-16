<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'product_id', 'size', 'color']);

            $table->dropColumn(['size', 'color', 'color_hex', 'price_override']);

            $table->string('barcode')->nullable()->after('sku');
            $table->string('attribute1_name')->nullable()->after('barcode');
            $table->string('attribute1_value')->nullable()->after('attribute1_name');
            $table->string('attribute2_name')->nullable()->after('attribute1_value');
            $table->string('attribute2_value')->nullable()->after('attribute2_name');
            $table->decimal('price_adjustment', 15, 2)->nullable()->after('attribute2_value');
            $table->decimal('cost_adjustment', 15, 2)->nullable()->after('price_adjustment');

            $table->unique(['business_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'sku']);

            $table->dropColumn([
                'barcode', 'attribute1_name', 'attribute1_value',
                'attribute2_name', 'attribute2_value',
                'price_adjustment', 'cost_adjustment',
            ]);

            $table->string('size');
            $table->string('color');
            $table->string('color_hex')->nullable();
            $table->decimal('price_override', 15, 4)->nullable();

            $table->unique(['business_id', 'product_id', 'size', 'color']);
        });
    }
};
