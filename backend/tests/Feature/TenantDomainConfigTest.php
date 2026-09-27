<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

/**
 * Tenant domains must adapt to the environment, never to a hardcoded host.
 *
 * Covers the config contract (`central_domains` follows the same env vars as
 * `superx.tenant_domain`), the URL shapes `OnboardingService` produces in each
 * environment, and the `tenants:repair-domains` command that rewrites rows
 * written while an environment was misconfigured.
 *
 * No tenant database is created here: tenants are inserted with events muted so
 * stancl's CreateDatabase/MigrateDatabase pipeline never runs, which keeps the
 * suite on a plain RefreshDatabase.
 */
class TenantDomainConfigTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'superx-erp.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'superx.tenant_domain' => self::BASE,
            'superx.frontend_url' => '',
            'superx.dev_tenant_subdomains' => false,
        ]);
    }

    // ─── Config contract ─────────────────────────────────────────

    public function test_central_domains_includes_the_configured_base_domain(): void
    {
        $this->assertContains('superx-erp.com', config('tenancy.central_domains'));
        $this->assertContains('localhost', config('tenancy.central_domains'), 'local dev hosts stay central');
    }

    public function test_central_domains_is_not_hardcoded_to_localhost_only(): void
    {
        // The production host must be a central domain, otherwise
        // PreventAccessFromCentralDomains cannot protect the central app and
        // InitializeTenancyBySubdomain strips the wrong base.
        $central = config('tenancy.central_domains');

        $this->assertNotSame(['127.0.0.1', 'localhost'], $central);
        $this->assertTrue(
            (bool) array_filter($central, fn (string $host) => ! in_array($host, ['localhost', '127.0.0.1'], true)),
            'central_domains carries at least one non-local host'
        );
    }

    public function test_base_domain_reads_config_without_a_literal_fallback(): void
    {
        config(['superx.tenant_domain' => 'stores.example.org']);

        $this->assertSame('stores.example.org', app(OnboardingService::class)->baseDomain());
        $this->assertSame('albaraka.stores.example.org', app(OnboardingService::class)->buildDomain('AlBaraka'));
    }

    // ─── URL shapes per environment ──────────────────────────────

    public function test_production_urls_use_the_base_domain_over_https(): void
    {
        $onboarding = app(OnboardingService::class);

        $this->assertSame('https://albaraka.'.self::BASE, $onboarding->storeUrl('albaraka.'.self::BASE));
        $this->assertSame('https://albaraka.'.self::BASE.'/login', $onboarding->storeUrl('albaraka.'.self::BASE, '/login'));
    }

    /**
     * The reported production failure: `SUPERX_FRONTEND_URL` left at the
     * development value must not leak `{sub}.localhost` into owner-facing
     * links once the environment is not local/testing.
     */
    public function test_a_development_frontend_url_cannot_leak_localhost_in_production(): void
    {
        config(['superx.frontend_url' => 'http://localhost:3000']);

        $onboarding = app(OnboardingService::class);

        $this->assertSame('http://albaraka.'.self::BASE, $onboarding->storeUrl('albaraka.'.self::BASE));
        $this->assertStringNotContainsString('localhost', $onboarding->storeUrl('albaraka.'.self::BASE));
    }

    public function test_dev_urls_collapse_onto_localhost_with_the_dev_port(): void
    {
        config([
            'superx.frontend_url' => 'http://localhost:3000',
            'superx.dev_tenant_subdomains' => true,
        ]);

        $onboarding = app(OnboardingService::class);

        $this->assertSame('http://albaraka.localhost:3000', $onboarding->storeUrl('albaraka.'.self::BASE));
        $this->assertSame('http://albaraka.localhost:3000/login', $onboarding->storeUrl('albaraka.'.self::BASE, 'login'));
    }

    public function test_a_remote_frontend_url_keeps_the_canonical_domain(): void
    {
        config([
            'superx.frontend_url' => 'https://app.example.org',
            'superx.dev_tenant_subdomains' => true,
        ]);

        $onboarding = app(OnboardingService::class);

        $this->assertSame('https://albaraka.'.self::BASE, $onboarding->storeUrl('albaraka.'.self::BASE));
    }

    // ─── Repair command ──────────────────────────────────────────

    public function test_repair_command_rewrites_misconfigured_domains(): void
    {
        $broken = $this->tenant('albaraka', 'albaraka.superx.com');
        $portLeaked = $this->tenant('brillivo', 'brillivo.localhost:3000');
        $correct = $this->tenant('techzone', 'techzone.'.self::BASE);

        $this->artisan('tenants:repair-domains', ['--force' => true])->assertSuccessful();

        $this->assertSame('albaraka.'.self::BASE, Domain::where('tenant_id', $broken)->value('domain'));
        $this->assertSame('brillivo.'.self::BASE, Domain::where('tenant_id', $portLeaked)->value('domain'));
        $this->assertSame('techzone.'.self::BASE, Domain::where('tenant_id', $correct)->value('domain'));

        // The mirrored tenant attribute stays in sync — the owner dashboard
        // and provisioning both read it.
        $this->assertSame('albaraka.'.self::BASE, Tenant::find($broken)->domain);
        $this->assertSame('brillivo.'.self::BASE, Tenant::find($portLeaked)->domain);

        // The already-correct row was left alone.
        $this->assertSame(1, Domain::where('domain', 'techzone.'.self::BASE)->count());
    }

    public function test_repair_command_is_a_dry_run_without_force(): void
    {
        $broken = $this->tenant('albaraka', 'albaraka.superx.com');

        $this->artisan('tenants:repair-domains')
            ->expectsOutputToContain('1 domain(s) would change')
            ->assertSuccessful();

        $this->assertSame('albaraka.superx.com', Domain::where('tenant_id', $broken)->value('domain'));
    }

    public function test_repair_command_is_idempotent(): void
    {
        $this->tenant('albaraka', 'albaraka.superx.com');

        $this->artisan('tenants:repair-domains', ['--force' => true])->assertSuccessful();
        $this->artisan('tenants:repair-domains')
            ->expectsOutputToContain('Nothing to repair')
            ->assertSuccessful();

        $this->assertSame(1, Domain::count());
    }

    public function test_repair_command_creates_a_domain_row_for_a_domain_less_tenant(): void
    {
        $tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'business_id' => $tenantId,
            'subdomain' => 'orphan',
        ]));

        $this->assertSame(0, Domain::count());

        $this->artisan('tenants:repair-domains', ['--force' => true])->assertSuccessful();

        $this->assertSame('orphan.'.self::BASE, Domain::where('tenant_id', $tenantId)->value('domain'));
    }

    public function test_repair_command_derives_the_subdomain_when_the_tenant_has_none(): void
    {
        $tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'business_id' => $tenantId,
        ]));
        $tenant->domains()->create(['domain' => 'leciel.superx.com']);

        $this->artisan('tenants:repair-domains', ['--force' => true])->assertSuccessful();

        $this->assertSame('leciel.'.self::BASE, Domain::where('tenant_id', $tenantId)->value('domain'));
    }

    public function test_repair_command_rejects_a_malformed_target_domain(): void
    {
        $this->tenant('albaraka', 'albaraka.superx.com');

        $this->artisan('tenants:repair-domains', ['--to' => 'https://superx-erp.com:8443/'])
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();

        $this->assertSame('albaraka.superx.com', Domain::first()->domain);
    }

    /** Insert a tenant + its (possibly misconfigured) domain without provisioning. */
    private function tenant(string $subdomain, string $domain): string
    {
        $tenantId = (string) Str::uuid();

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'business_id' => $tenantId,
            'subdomain' => $subdomain,
            'domain' => $domain,
        ]));
        $tenant->domains()->create(['domain' => $domain]);

        return $tenantId;
    }
}
