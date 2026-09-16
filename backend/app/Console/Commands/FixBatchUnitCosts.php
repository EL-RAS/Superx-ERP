<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixBatchUnitCosts extends Command
{
    protected $signature = 'batches:fix-unit-costs
        {--force : Skip confirmation prompt}
        {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Fix batch cost_per_unit values that were stored as total cost instead of per-unit cost';

    public function handle(): int
    {
        $this->info('Scanning for batches with cost_per_unit stored as total cost...');

        $batches = ProductBatch::where('quantity', '>', 0)->get();
        $fixes = [];

        foreach ($batches as $batch) {
            $product = $batch->product;
            if (!$product) continue;

            $priceThreshold = max((float) $product->price, 1);
            if ((float) $batch->cost_per_unit > $priceThreshold) {
                $oldCpu = (float) $batch->cost_per_unit;
                $newCpu = round($oldCpu / (float) $batch->quantity, 2);
                $fixes[] = [
                    'id' => $batch->id,
                    'product' => $product->name,
                    'batch' => $batch->batch_number,
                    'qty' => $batch->quantity,
                    'price' => $product->price,
                    'old_cpu' => $oldCpu,
                    'new_cpu' => $newCpu,
                ];
            }
        }

        if (empty($fixes)) {
            $this->info('✓ No batches found with cost_per_unit stored as total cost. All unit costs appear correct.');
            return Command::SUCCESS;
        }

        $this->warn(sprintf('Found %d batches with suspicious cost_per_unit (exceeds product price):', count($fixes)));
        $this->newLine();

        $headers = ['Batch', 'Product', 'Qty', 'Price', 'Current cost_per_unit', 'Fixed unit cost'];
        $rows = array_map(fn ($f) => [
            $f['batch'],
            $f['product'],
            $f['qty'],
            number_format($f['price'], 2),
            number_format($f['old_cpu'], 2) . ' ← likely total',
            number_format($f['new_cpu'], 2) . ' ← corrected',
        ], $fixes);

        $this->table($headers, $rows);

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes applied.');
            return Command::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Apply these fixes?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($fixes));
        $bar->start();

        $affectedProductIds = [];

        DB::transaction(function () use ($fixes, $bar, &$affectedProductIds) {
            foreach ($fixes as $fix) {
                ProductBatch::where('id', $fix['id'])->update(['cost_per_unit' => $fix['new_cpu']]);
                $affectedProductIds[] = $fix['id'];
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Recalculate costs for all products that had fixed batches
        $productIds = ProductBatch::whereIn('id', array_column($fixes, 'id'))
            ->pluck('product_id')
            ->unique()
            ->values()
            ->toArray();

        $this->info(sprintf('Recalculating WAC for %d affected products...', count($productIds)));

        $wacBar = $this->output->createProgressBar(count($productIds));
        $wacBar->start();

        foreach ($productIds as $pid) {
            $product = Product::find($pid);
            if ($product) {
                $product->recalculateStockQuantity();
                $product->updateWeightedAverageCost();
            }
            $wacBar->advance();
        }

        $wacBar->finish();
        $this->newLine(2);

        $this->info('✓ Batch unit costs fixed successfully!');
        $this->warn('Note: Refresh the products page to see corrected unit costs.');

        return Command::SUCCESS;
    }
}
