<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_batches')
            ->where('quantity', '>', 0)
            ->update([
                'cost_per_unit' => DB::raw('cost_per_unit / quantity'),
            ]);

        Product::withTrashed()->chunk(100, function ($products) {
            foreach ($products as $product) {
                $product->recalculateStockQuantity();
                $product->updateWeightedAverageCost();
            }
        });
    }

    public function down(): void
    {
    }
};
