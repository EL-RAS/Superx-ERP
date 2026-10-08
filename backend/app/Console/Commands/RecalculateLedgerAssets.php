<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Tenant;
use App\Services\LedgerRepairService;
use Illuminate\Console\Command;

class RecalculateLedgerAssets extends Command
{
    protected $signature = 'inventory:recalculate-ledger-assets
        {--business= : Restrict to one business (slug or id)}
        {--tenant= : Restrict to one tenant (id or subdomain)}
        {--dry-run : Audit and report only - post nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Audit and correct Inventory Asset (1030) and COGS (5010) against real stock and the frozen cost of every sale';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantOption = $this->option('tenant');

        $tenants = Tenant::query()
            ->when($tenantOption, function ($query) use ($tenantOption) {
                $query->where(fn ($q) => $q
                    ->where('id', $tenantOption)
                    ->orWhereHas('domains', fn ($domains) => $domains->where('subdomain', $tenantOption)));
            })
            ->get();

        if ($tenantOption && $tenants->isEmpty()) {
            $this->error("No tenant matched '{$tenantOption}'.");

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->option('force')) {
            $scope = $tenants->isEmpty() ? 'this connection' : $tenants->count().' tenant(s)';
            if (! $this->confirm("Post correcting journal entries across {$scope}?")) {
                $this->info('Aborted - nothing was posted.');

                return self::SUCCESS;
            }
        }

        if ($tenants->isEmpty()) {
            return $this->runForCurrentConnection($dryRun);
        }

        $failures = 0;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->info('Tenant '.$tenant->id);

            try {
                if ($tenant->run(fn () => $this->runForCurrentConnection($dryRun)) !== 0) {
                    $failures++;
                }
            } catch (\Throwable $e) {
                $this->error('  Skipped: '.$e->getMessage());
                $failures++;
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function runForCurrentConnection(bool $dryRun): int
    {
        $option = $this->option('business');

        $businesses = Business::query()
            ->when($option, fn ($query) => $query->where(fn ($q) => $q
                ->where('slug', $option)
                ->orWhere('id', $option)))
            ->get();

        if ($businesses->isEmpty()) {
            $this->error('No businesses found.');

            return self::FAILURE;
        }

        $service = app(LedgerRepairService::class);
        $failures = 0;

        foreach ($businesses as $business) {
            try {
                $result = $dryRun
                    ? $service->audit($business->id)
                    : $service->repair($business->id);
            } catch (\Throwable $e) {
                $this->error("{$business->name}: {$e->getMessage()}");
                $failures++;

                continue;
            }

            $this->report($business, $result, $dryRun);
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(Business $business, array $result, bool $dryRun): void
    {
        $prefix = $dryRun ? '[dry-run] ' : '';
        $cogs = $result['cogs'];

        $this->info(sprintf(
            '%s%s: COGS - %d invoice(s) audited, %d drifted, net %+.2f',
            $prefix,
            $business->name,
            $cogs['invoices_audited'],
            $cogs['invoices_with_drift'],
            $cogs['net_drift'],
        ));

        foreach ($cogs['invoices'] as $row) {
            if ($row['status'] === 'drifted') {
                $this->line(sprintf(
                    '    %s: %s -> %s (%+.2f)',
                    $row['invoice_number'] ?? 'invoice #'.$row['invoice_id'],
                    $row['posted'],
                    $row['should_be'],
                    $row['delta'],
                ));
            } elseif ($row['status'] === 'orphaned') {
                $this->warn('    invoice #'.$row['invoice_id'].' has cost on 5010 but no invoice row - left untouched.');
            }
        }

        foreach ($cogs['corrections'] ?? [] as $correction) {
            $this->line('    posted entry #'.$correction['entry_id'].' for '.$correction['invoice_number']);
        }

        $inventory = $result['inventory'];
        if (! ($inventory['ok'] ?? false)) {
            $this->error('    Inventory Asset: '.$inventory['message']);

            return;
        }

        $this->line(sprintf(
            '    Inventory Asset: %s (value=%s, 1030=%s, delta=%+.2f)',
            $inventory['message'],
            $result['stock_value'],
            $inventory['current_balance'],
            $inventory['delta'],
        ));
    }
}
