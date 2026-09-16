<?php

namespace App\Console\Commands;

use App\Models\ActivationToken;
use App\Models\Business;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\DatabaseConfig;

class PurgeOrphanTenants extends Command
{
    /**
     * Demo stores created by BusinessSeeder. Before the seeding pipeline
     * provisioned tenants (TenantSeeder first), these slugs existed as plain
     * central Business rows with no stancl tenant, no lead and no activation —
     * older dev databases carry those unprovisioned leftovers.
     */
    private const DEMO_SLUGS = [
        'super-retail',
        'fashion-hub',
        'techzone',
        'byte-burger',
        'le-ciel',
        'smile-dental',
        'al-shifa-clinic',
        'al-karim-jewelry',
    ];

    protected $signature = 'tenants:purge-orphans
        {--force : Actually delete the candidates; default lists them}
        {--slugs= : Extra comma-separated business slugs to treat as legacy}
        {--legacy-all : Also treat every tenant-less business as legacy (dangerous)}';

    protected $description = 'Delete unprovisioned/legacy dummy demo rows left by older seeds (orphan Business/Tenant/Domain rows)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $extraSlugs = $this->option('slugs');
        $legacyAll = (bool) $this->option('legacy-all');

        $tenantIds = collect(Tenant::pluck('id'))->map(fn ($id) => (string) $id)->all();
        $businessIds = collect(Business::pluck('id'))->map(fn ($id) => (string) $id)->all();
        $domains = Domain::all()->groupBy('tenant_id');

        // (A) Demo-named Business mirrors that were never provisioned: no
        // stancl tenant is the only reliable discriminator (plan defaults to
        // 'standard' for every row, and manual stores legitimately have no
        // tenant). Matching the demo slugs keeps real/manual stores untouched.
        $slugs = array_merge(self::DEMO_SLUGS, $extraSlugs ? explode(',', $extraSlugs) : []);
        $orphanBusinesses = Business::query()
            ->whereNotIn('id', $tenantIds)
            ->when($legacyAll, fn ($query) => $query, fn ($query) => $query->whereIn('slug', $slugs))
            ->orderBy('name')
            ->get()
            ->values();

        // (B) Stancl tenants whose central Business mirror is gone.
        $danglingTenants = Tenant::all()
            ->filter(fn (Tenant $t) => ! in_array((string) $t->id, $businessIds, true))
            ->values();
        $hadDomains = fn (Tenant $t) => ($domains->get((string) $t->id) ?? collect())->isNotEmpty();

        // (C) Tenants that hold no domain record — provisioning never finished.
        $domainlessTenants = Tenant::all()->filter(fn (Tenant $t) => ! $hadDomains($t))->values();

        // (D) Domain rows pointing at a tenant that no longer exists.
        $orphanDomains = $domains
            ->filter(fn ($rows, string $tenantId) => ! in_array($tenantId, $tenantIds, true))
            ->flatten()
            ->values();

        $this->info(sprintf('Found %d orphan business(es), %d dangling tenant(s), %d domain-less tenant(s), %d orphan domain(s).', $orphanBusinesses->count(), $danglingTenants->count(), $domainlessTenants->count(), $orphanDomains->count()));

        foreach ($orphanBusinesses as $business) {
            $this->warn(sprintf('  business %-38s %s (%s)', $business->name, $business->id, $business->slug));
        }
        foreach ($danglingTenants->merge($domainlessTenants)->unique('id') as $tenant) {
            $this->warn(sprintf('  tenant  %s (db: %s)', $tenant->id, (new DatabaseConfig($tenant))->getName()));
        }
        foreach ($orphanDomains as $domain) {
            $this->warn(sprintf('  domain  %-40s %s', $domain->domain, $domain->tenant_id));
        }

        if ($orphanBusinesses->isEmpty() && $danglingTenants->isEmpty() && $domainlessTenants->isEmpty() && $orphanDomains->isEmpty()) {
            $this->info('Nothing to purge.');

            return Command::SUCCESS;
        }

        if (! $force) {
            $this->error('Run with --force to delete these rows.');

            return Command::FAILURE;
        }

        foreach ($danglingTenants->merge($domainlessTenants)->unique('id') as $tenant) {
            $config = new DatabaseConfig($tenant);
            try {
                DB::connection()->statement('DROP DATABASE IF EXISTS "'.$config->getName().'"');
            } catch (\Throwable) {
                // database may be missing/in use — the delete below still cleans the row
            }
            $tenant->delete();
        }

        foreach ($orphanDomains as $domain) {
            $domain->delete();
        }

        foreach ($orphanBusinesses as $business) {
            foreach (Lead::where('tenant_business_id', $business->id)->get() as $lead) {
                ActivationToken::where('lead_id', $lead->id)->delete();
                $lead->delete();
            }
            ActivationToken::where('business_id', $business->id)->delete();
            $business->delete();
        }

        $this->info('Purge complete.');

        return Command::SUCCESS;
    }
}
