<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Replaces Stancl\Tenancy\Jobs\SeedDatabase.
 *
 * The vendored job shells out to `tenants:seed --tenants=...`, but with
 * Laravel 11 the SeedCommand uses a fluent $signature, so the
 * HasATenantsOption trait's getOptions() merge is never applied — the
 * --tenants option does not exist and seeding crashes the pipeline.
 *
 * This job resolves the configured seeder class and runs it directly
 * inside the tenant context (same effect, no artisan shell-out).
 */
class SeedTenantDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var TenantWithDatabase */
    protected $tenant;

    public function __construct(TenantWithDatabase $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(): void
    {
        $seederClass = $this->resolveSeederClass();

        if (! $seederClass) {
            return;
        }

        $this->tenant->run(function () use ($seederClass) {
            $seeder = app()->make($seederClass);

            Model::unguarded(function () use ($seeder) {
                $seeder->__invoke();
            });
        });
    }

    /**
     * Config seeder_parameters['--class'] may hold the bare class name
     * ('TenantDatabaseSeeder'); map it to the PSR-4 namespace Laravel uses.
     */
    private function resolveSeederClass(): ?string
    {
        $class = config('tenancy.seeder_parameters', [])['--class'] ?? null;

        if (! $class) {
            return null;
        }

        if (class_exists($class)) {
            return $class;
        }

        $namespaced = 'Database\\Seeders\\'.ltrim($class, '\\');

        return class_exists($namespaced) ? $namespaced : null;
    }
}
