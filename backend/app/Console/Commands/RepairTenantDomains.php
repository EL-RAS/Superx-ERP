<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\OnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Rewrites stored tenant domains onto the currently configured base domain.
 *
 * `domains.domain` is the column tenant resolution keys off, so a row written
 * while the environment was misconfigured (`{sub}.superx.com`, `{sub}.localhost`,
 * `{sub}.localhost:3000`) keeps breaking navigation after the config is fixed.
 * The subdomain itself is always right — only the base domain is wrong — so
 * every row is rebuilt from `Tenant::subdomain` rather than string-patched.
 *
 * Dry-run by default. Always review the plan before passing --force.
 */
class RepairTenantDomains extends Command
{
    protected $signature = 'tenants:repair-domains
        {--force : Apply the changes; default reports the plan only}
        {--to= : Target base domain (default: config superx.tenant_domain)}';

    protected $description = 'Rewrite stored tenant domains onto the configured base domain (dry-run unless --force)';

    public function handle(OnboardingService $onboarding): int
    {
        $target = strtolower(trim((string) ($this->option('to') ?: $onboarding->baseDomain())));
        $target = trim($target, '.');

        if ($target === '' || str_contains($target, '/') || str_contains($target, ':')) {
            $this->error('Refusing to run: the target base domain must be a bare host (no scheme, port or path).');

            return Command::FAILURE;
        }

        $force = (bool) $this->option('force');

        $this->info(sprintf('Base domain: <info>%s</info>%s', $target, $force ? '' : '  (dry run)'));
        $this->newLine();

        /** @var array<string, Domain> $current current domain row per tenant id */
        $current = Domain::all()->groupBy('tenant_id')->map(fn ($rows) => $rows->first())->all();

        $plan = [];
        $unresolvable = [];
        $seen = [];

        foreach (Tenant::all() as $tenant) {
            $existing = $current[(string) $tenant->id] ?? null;
            $from = $existing?->domain;

            // The subdomain is the only trustworthy identifier: it is written at
            // provisioning time and never derived from the frontend origin.
            $subdomain = $this->subdomainOf($tenant, $from);

            if ($subdomain === null) {
                $unresolvable[] = [$tenant, $from];

                continue;
            }

            $to = $subdomain.'.'.$target;

            if ($from === $to) {
                continue;
            }

            if (isset($seen[$to])) {
                $this->error(sprintf(
                    'CONFLICT %s -> %s (already claimed by tenant %s). Resolve manually.',
                    (string) $from,
                    $to,
                    $seen[$to]
                ));

                return Command::FAILURE;
            }

            $seen[$to] = (string) $tenant->id;
            $plan[] = compact('tenant', 'existing', 'from', 'to');
        }

        foreach ($unresolvable as [$tenant, $from]) {
            $this->warn(sprintf('SKIP tenant %s — no subdomain attribute and no usable domain (%s).', (string) $tenant->id, (string) $from));
        }

        if ($plan === []) {
            $this->info('Nothing to repair — every stored domain already matches the base domain.');

            return Command::SUCCESS;
        }

        foreach ($plan as $row) {
            $this->line(sprintf(
                '  %-46s -> %s',
                (string) ($row['from'] ?? '(none)'),
                $row['to']
            ));
        }

        $this->newLine();
        $this->info(sprintf('%d domain(s) would change.', count($plan)));

        if (! $force) {
            $this->error('Run with --force to apply.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $row) {
                /** @var Tenant $tenant */
                $tenant = $row['tenant'];

                if ($row['existing'] instanceof Domain) {
                    $row['existing']->update(['domain' => $row['to']]);
                } else {
                    $tenant->domains()->create(['domain' => $row['to']]);
                }

                // Keep the mirrored `domain` attribute on the tenant row in sync;
                // provisioning writes both and the owner dashboard reads both.
                $tenant->forceFill(['domain' => $row['to']])->save();
            }
        });

        $this->info('Repair complete.');

        return Command::SUCCESS;
    }

    /**
     * Prefer the tenant's own `subdomain`; fall back to the first label of the
     * existing domain, stripping any port and the base domain.
     */
    private function subdomainOf(Tenant $tenant, ?string $domain): ?string
    {
        $subdomain = strtolower(trim((string) $tenant->subdomain));

        if ($subdomain !== '' && preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $subdomain) === 1) {
            return $subdomain;
        }

        if (! is_string($domain) || $domain === '') {
            return null;
        }

        $host = strtolower(trim($domain));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $label = explode('.', $host)[0];

        return preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $label) === 1 ? $label : null;
    }
}
