<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\InventorySyncService;
use Illuminate\Console\Command;

class SyncInventoryAccounting extends Command
{
    protected $signature = 'inventory:sync-accounting
        {--business= : Business slug to sync (defaults to all businesses)}
        {--dry-run : Show what would be posted without posting}';

    protected $description = 'Reconcile the 1030 Inventory Asset account with actual on-hand stock value';

    public function handle(): int
    {
        $query = Business::query();

        if ($slug = $this->option('business')) {
            $query->where('slug', $slug);
        }

        $businesses = $query->get();

        if ($businesses->isEmpty()) {
            $this->error('No businesses found.');

            return Command::FAILURE;
        }

        $service = app(InventorySyncService::class);
        $dryRun = (bool) $this->option('dry-run');

        foreach ($businesses as $business) {
            $result = $service->sync($business->id, null, $dryRun);

            if (! $result['ok']) {
                $this->error("{$business->name}: {$result['message']}");

                continue;
            }

            if (! $result['changed']) {
                $this->info(
                    "{$business->name}: {$result['message']} (stock_value={$result['stock_value']}, 1030={$result['current_balance']})"
                );

                continue;
            }

            $this->info(
                "{$business->name}: posted {$result['delta']} adjustment - entry #{$result['entry_id']}, 1030 balance now {$result['new_balance']}"
            );
        }

        return Command::SUCCESS;
    }
}
