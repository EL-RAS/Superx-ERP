<?php

namespace App\Console\Commands;

use App\Models\InventoryAdjustment;
use App\Models\ProductBatch;
use App\Services\AccountingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoWasteExpiredBatches extends Command
{
    protected $signature = 'inventory:auto-waste {--dry-run : Preview without making changes}';

    protected $description = 'Scan expired batches and create waste adjustments';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $now = now();

        $expiredBatches = ProductBatch::withoutBusiness()
            ->where('expiry_date', '<', $now)
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->with('product:id,business_id,stock_quantity,name,cost')
            ->get();

        if ($expiredBatches->isEmpty()) {
            $this->info('No expired batches with remaining stock found.');

            return Command::SUCCESS;
        }

        $totalFound = $expiredBatches->count();
        $this->line("Found {$totalFound} expired batch(es) with remaining stock.");
        $created = 0;

        foreach ($expiredBatches as $batch) {
            $remaining = (float) $batch->quantity - (float) $batch->quantity_sold;
            $product = $batch->product;

            if (! $product) {
                $this->warn("Batch #{$batch->id}: orphaned, skipping.");

                continue;
            }

            $productName = $product?->name ?? 'Unknown';
            $this->line("  {$batch->batch_number} ({$productName}): {$remaining} units expired on {$batch->expiry_date->format('Y-m-d')}");

            if ($dryRun) {
                $created++;

                continue;
            }

            try {
                DB::beginTransaction();

                $adjustmentNumber = 'AUTO-'.strtoupper(Str::random(8));

                $adjustment = InventoryAdjustment::create([
                    'business_id' => $product->business_id,
                    'user_id' => 1,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'adjustment_number' => $adjustmentNumber,
                    'type' => 'waste',
                    'quantity_before' => (float) $product->stock_quantity,
                    'quantity_adjusted' => -$remaining,
                    'quantity_after' => max(0, (float) $product->stock_quantity - $remaining),
                    'reason' => null,
                    'notes' => "Automated Adjustment: Batch expired on {$batch->expiry_date->format('Y-m-d')}",
                    'status' => 'completed',
                ]);

                $batch->increment('quantity_sold', $remaining);

                $product->recalculateStockQuantity();
                $product->updateWeightedAverageCost();

                $service = app(AccountingService::class);
                $service->ensureChartOfAccounts($product->business_id);

                $unitCost = (float) ($batch->cost_per_unit ?? 0);
                if ($unitCost <= 0) {
                    $unitCost = (float) $product->cost;
                }
                $service->postInventoryAdjustmentEntry(
                    $product->business_id,
                    $adjustment,
                    $remaining * $unitCost,
                    $adjustment->user_id
                );

                DB::commit();
                $created++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        if (! $dryRun) {
            $this->info("Created {$created} waste adjustment(s).");
        } else {
            $this->info("Dry-run complete. {$totalFound} would be processed.");
        }

        return Command::SUCCESS;
    }
}
