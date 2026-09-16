<?php

namespace Tests\Feature;

use App\Models\ActivationToken;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

/**
 * End-to-end B2B onboarding workflow.
 *
 * NOTE: this suite does NOT use RefreshDatabase — provisioning runs stancl's
 * CreateDatabase job which issues `CREATE DATABASE`, and Postgres refuses DDL
 * inside the transaction block that RefreshDatabase opens. Instead we run
 * `migrate:fresh` per test and drop every tenant database we create in
 * tearDown (the tenant DBs live outside the central test DB).
 */
class OnboardingWorkflowTest extends TestCase
{
    private BusinessType $businessType;

    /** @var list<string> tenant ids (their DB prefix + id = physical DB name) */
    private array $createdTenantIds = [];

    /** Platform owner reused within a single test (owner usernames are unique). */
    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Force a fresh central schema even if another suite bumped the static guard.
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh');

        // Pin the dev frontend origin so tenant URLs resolve to subdomain.localhost
        // (a leaked override from another test would break the env-aware asserts).
        config(['superx.frontend_url' => 'http://localhost:3000']);

        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
            'default_settings' => [
                'allow_split_payments' => true,
                'barcode_scanner' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        // Drop the physical tenant databases created during this test. The
        // central tenant/domain rows vanish on the next example's migrate:fresh.
        foreach ($this->createdTenantIds as $tenantId) {
            try {
                DB::connection('pgsql')->statement('DROP DATABASE IF EXISTS "'.'tenant'.$tenantId.'"');
            } catch (\Throwable) {
                // database may still be in use / already gone — best effort
            }
        }

        $this->createdTenantIds = [];

        parent::tearDown();
    }

    private function submitLead(array $overrides = []): int
    {
        $attrs = array_merge([
            'name' => 'Sami Owner',
            'business_name' => 'Sami Superstore',
            'business_type_id' => $this->businessType->id,
            'phone' => '+962 0785555555',
            'email' => 'sami@superstore.test',
            'subdomain' => 'sami-superstore',
            'city' => 'Amman',
        ], $overrides);

        $res = $this->postJson('/api/v1/leads', $attrs);

        return (int) $res->json('lead.id');
    }

    private function actingAsOwner(): User
    {
        if ($this->owner) {
            $this->actingAs($this->owner, 'sanctum');

            return $this->owner;
        }

        $owner = User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'SuperX Owner',
            'username' => 'superx-owner-onboarding',
            'email' => 'onboarding-owner@superx.test',
            'password' => Hash::make('OwnerPass1@'),
            'role' => 'superx_owner',
            'is_active' => true,
        ]);

        $this->owner = $owner;
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function provisionLead(int $leadId, string $activationToken): Tenant
    {
        $activation = ActivationToken::findOrFail($activationToken);
        $tenant = Tenant::find($activation->tenant_id);
        $this->assertNotNull($tenant, 'provisioning must create a stancl tenant');
        $this->createdTenantIds[] = (string) $tenant->id;

        return $tenant;
    }

    // ─── Lead capture with B2B fields ────────────────────────────

    public function test_lead_capture_accepts_email_and_subdomain(): void
    {
        $leadId = $this->submitLead();

        $lead = Lead::find($leadId);
        $this->assertNotNull($lead);
        $this->assertSame('+962785555555', $lead->phone);
        $this->assertSame('sami@superstore.test', $lead->email);
        $this->assertSame('sami-superstore', $lead->subdomain);
        $this->assertSame('new', $lead->status);
    }

    public function test_lead_capture_rejects_invalid_subdomain_and_email(): void
    {
        $this->postJson('/api/v1/leads', [
            'name' => 'X',
            'business_name' => 'Y Store',
            'business_type_id' => $this->businessType->id,
            'phone' => '+962 0785555555',
            'email' => 'not-an-email',
            'subdomain' => 'Bad_Subdomain!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'subdomain']);
    }

    // ─── Owner approval & provisioning ───────────────────────────

    public function test_owner_approves_lead_provisioning_tenant_domain_and_token(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();

        $res = $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();

        $lead = Lead::find($leadId);
        $this->assertSame('provisioned', $lead->status);
        $this->assertNotNull($lead->tenant_business_id);
        $this->assertNotNull($lead->provisioned_at);

        // Central mirror business.
        $business = Business::find($lead->tenant_business_id);
        $this->assertNotNull($business);
        $this->assertSame('activating', $business->status);
        $this->assertSame('Sami Superstore', $business->name);
        $this->assertSame($this->businessType->id, $business->business_type_id);
        $this->assertSame(5, Role::withoutBusiness()->where('business_id', $business->id)->count(), 'system roles seeded');
        $this->assertSame(true, $business->settings['allow_split_payments'], 'type default settings applied');

        // Tenant + domain (VirtualColumn exposes data keys as top-level attributes).
        $tenant = Tenant::find($business->id);
        $this->assertNotNull($tenant);
        $this->assertSame('sami-superstore', $tenant->subdomain);
        $this->assertSame('sami-superstore.superx.com', $tenant->domain);
        $this->assertSame($leadId, $tenant->lead_id);

        $domain = Domain::where('domain', 'sami-superstore.superx.com')->first();
        $this->assertNotNull($domain);
        $this->assertSame($tenant->id, $domain->tenant_id);
        $this->createdTenantIds[] = (string) $tenant->id;

        // Activation token.
        $token = ActivationToken::where('lead_id', $leadId)->first();
        $this->assertNotNull($token);
        $this->assertSame($tenant->id, $token->tenant_id);
        $this->assertSame($business->id, $token->business_id);
        $this->assertTrue($token->isValid());
        $this->assertStringContainsString($token->token, $res->json('activation_url'));
        $this->assertStringContainsString('/activate', $res->json('activation_url'));

        // Environment-aware URLs: in dev (frontend URL = localhost) the
        // production base domain is swapped for `localhost` + the dev port.
        $this->assertSame('http://sami-superstore.localhost:3000', $res->json('store_url'));
        $this->assertStringStartsWith('http://sami-superstore.localhost:3000/activate?token=', $res->json('activation_url'));

        // The real tenant database was created, migrated & seeded.
        $seededShop = null;
        $tenant->run(function () use (&$seededShop) {
            $seededShop = DB::table('businesses')->first();
        });
        $this->assertNotNull($seededShop, 'tenant shop row seeded by TenantDatabaseSeeder');
        $this->assertSame($business->id, $seededShop->id);
        $this->assertSame('activating', $seededShop->status);
    }

    public function test_provision_generates_production_store_urls_when_frontend_is_remote(): void
    {
        config(['superx.frontend_url' => 'https://app.superx.com']);
        $leadId = $this->submitLead();
        $this->actingAsOwner();

        $res = $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();

        $this->assertSame('https://sami-superstore.superx.com', $res->json('store_url'));
        $this->assertStringStartsWith('https://sami-superstore.superx.com/activate?token=', $res->json('activation_url'));
        $this->createdTenantIds[] = $res->json('business_id');
    }

    public function test_owner_cannot_reapprove_an_already_provisioned_lead(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $first = $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();
        $tenantId = $first->json('business_id');
        $this->createdTenantIds[] = $tenantId;

        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lead']);
    }

    public function test_owner_approves_lead_with_plan_and_subscription_term_applied(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();

        $res = $this->postJson("/api/v1/platform/leads/{$leadId}/approve", [
            'plan' => 'premium',
            'subscription_days' => 90,
        ])->assertCreated();

        $this->assertSame('premium', $res->json('plan'));
        $this->assertSame('sami-superstore', $res->json('subdomain'));
        $this->assertSame('Sami Superstore', $res->json('lead.business_name'));

        // Central mirror business carries the plan + subscription term.
        $business = Business::find($res->json('business_id'));
        $this->assertSame('premium', $business->plan);
        $this->assertEquals(now()->addDays(90)->toDateString(), $business->expires_at->toDateString());

        // The tenant `shop` payload mirrors the plan + term for the seeded DB.
        $tenant = Tenant::find($business->id);
        $this->createdTenantIds[] = (string) $tenant->id;
        $this->assertSame('premium', $tenant->shop['plan']);
        $this->assertEquals(now()->addDays(90)->toDateString(), $tenant->shop['expires_at']);

        // Physical tenant database name (prefix + tenant uuid) is returned.
        $this->assertSame('tenant'.$business->id, $res->json('database'));
        $this->assertSame('http://sami-superstore.localhost:3000', $res->json('store_url'));
    }

    public function test_approve_via_env_secret_without_user_provisions_tenant(): void
    {
        config(['superx.owner_secret' => 'approve-secret-key']);
        $leadId = $this->submitLead();

        // No authenticated owner — the X-Owner-Secret path grants access and
        // $request->user() is null (previously a TypeError in provision()).
        $res = $this->postJson("/api/v1/platform/leads/{$leadId}/approve", [], [
            'X-Owner-Secret' => 'approve-secret-key',
        ])->assertCreated();

        $this->assertSame('provisioned', $res->json('lead.status'));
        $this->assertNotNull($res->json('business_id'));
        $this->assertNotNull($res->json('activation_url'));
        $this->assertSame('standard', $res->json('plan'));

        // approved_by stays null since there is no authenticated owner.
        $this->assertNull(Lead::find($leadId)->approved_by);

        $tenant = ActivationToken::where('lead_id', $leadId)->firstOrFail();
        $this->createdTenantIds[] = (string) $tenant->tenant_id;
    }

    public function test_owner_rejects_lead_and_rejected_leads_cannot_be_approved(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();

        $this->postJson("/api/v1/platform/leads/{$leadId}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $lead = Lead::findOrFail($leadId);
        $this->assertNotNull($lead->metadata['rejected_at'] ?? null);
        $this->assertSame('rejected', $lead->status);

        // A rejected lead can no longer be approved (stays rejected).
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lead']);

        // Rejecting twice is also blocked.
        $this->postJson("/api/v1/platform/leads/{$leadId}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lead']);
    }

    public function test_reject_requires_owner(): void
    {
        $leadId = $this->submitLead();

        $this->postJson("/api/v1/platform/leads/{$leadId}/reject")->assertStatus(404);
        $this->assertSame('new', Lead::find($leadId)->status);
    }

    public function test_lead_approve_requires_owner(): void
    {
        $leadId = $this->submitLead();

        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertStatus(404);
    }

    // ─── Activation wizard ───────────────────────────────────────

    public function test_activation_wizard_previews_business_and_creates_root_admin(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();

        $token = ActivationToken::where('lead_id', $leadId)->firstOrFail();

        // Preview.
        $this->getJson('/api/v1/activate/'.$token->token)->assertOk()
            ->assertJsonPath('business.name', 'Sami Superstore')
            ->assertJsonPath('business.subdomain', 'sami-superstore')
            ->assertJsonPath('business.domain', 'sami-superstore.superx.com')
            ->assertJsonPath('business.contact.email', 'sami@superstore.test')
            ->assertJsonPath('business.business_type.slug', 'supermarket');

        // Activate.
        $res = $this->postJson('/api/v1/activate', [
            'token' => $token->token,
            'name' => 'Sami Owner',
            'email' => 'sami@superstore.test',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
            'currency' => 'JOD',
            'default_tax_rate' => 10,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('business.name', 'Sami Superstore');

        // Central mirror: business active + settings persisted, user provisioned.
        $business = Business::find($res->json('business.id'));
        $this->assertSame('active', $business->status);
        $this->assertSame('JOD', $business->settings['currency']);
        $this->assertSame(10, $business->settings['default_tax_rate']);

        $mirror = User::withoutBusiness()->where('business_id', $business->id)->first();
        $this->assertNotNull($mirror);
        $this->assertSame('sami@superstore.test', $mirror->email);
        $this->assertSame('admin', $mirror->role);
        $this->assertTrue((bool) $mirror->is_primary_admin);

        // Token now consumed.
        $this->assertFalse($token->fresh()->isValid());

        // Root admin lives inside the tenant DB.
        $activation = ActivationToken::find($token->id);
        $tenant = Tenant::find($activation->tenant_id);
        $this->createdTenantIds[] = (string) $tenant->id;

        $tenantUser = null;
        $tenantShopStatus = null;
        $tenant->run(function () use (&$tenantUser, &$tenantShopStatus, $mirror) {
            $tenantUser = DB::table('users')->where('username', $mirror->username)->first();
            $tenantShopStatus = DB::table('businesses')->where('id', $mirror->business_id)->value('status');
        });

        $this->assertNotNull($tenantUser);
        $this->assertSame($mirror->username, $tenantUser->username);
        $this->assertTrue(Hash::check('StrongPass9!', $tenantUser->password));
        $this->assertSame('active', $tenantShopStatus);

        // Tenant marked activated.
        $this->assertNotNull($tenant->fresh()->activated_at);
    }

    public function test_activation_accepts_custom_username(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();
        $token = ActivationToken::where('lead_id', $leadId)->firstOrFail();

        $res = $this->postJson('/api/v1/activate', [
            'token' => $token->token,
            'name' => 'Sami Owner',
            'email' => 'sami@superstore.test',
            'username' => 'Brillivo_Admin',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
        ])->assertOk();

        // The wizard shows the custom handle and its login URL is env-aware.
        $this->assertSame('brillivo_admin', $res->json('onboarding.username'));
        $this->assertSame('http://sami-superstore.localhost:3000/login', $res->json('onboarding.login_url'));

        // Mirror user + tenant-root user BOTH carry the custom handle.
        $mirror = User::withoutBusiness()->where('business_id', $res->json('business.id'))->first();
        $this->assertSame('brillivo_admin', $mirror->username);

        $tenant = Tenant::find($token->tenant_id);
        $this->createdTenantIds[] = (string) $tenant->id;

        $tenantUsername = null;
        $tenant->run(function () use (&$tenantUsername, $mirror) {
            $tenantUsername = DB::table('users')->where('business_id', $mirror->business_id)->value('username');
        });
        $this->assertSame('brillivo_admin', $tenantUsername);
    }

    public function test_activation_rejects_invalid_username(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();
        $token = ActivationToken::where('lead_id', $leadId)->firstOrFail();

        $this->postJson('/api/v1/activate', [
            'token' => $token->token,
            'name' => 'Sami',
            'email' => 'sami@superstore.test',
            'username' => 'Bad Name!',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);

        $this->createdTenantIds[] = (string) $token->tenant_id;
    }

    public function test_activation_rejects_weak_password_and_invalid_token(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();
        $token = ActivationToken::where('lead_id', $leadId)->firstOrFail();

        $this->postJson('/api/v1/activate', [
            'token' => $token->token,
            'name' => 'Sami',
            'email' => 'sami@superstore.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/activate', [
            'token' => 'x'.str_repeat('x', 63),
            'name' => 'Sami',
            'email' => 'sami@superstore.test',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);

        $this->getJson('/api/v1/activate/'.'y'.str_repeat('y', 63))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);

        $this->createdTenantIds[] = (string) $token->tenant_id;
    }

    // ─── Tenant-domain login ─────────────────────────────────────

    private function activatedTenant(string $subdomain = 'sami-superstore'): Tenant
    {
        $leadId = $this->submitLead([
            'subdomain' => $subdomain,
            'email' => 'sami@'.$subdomain.'.test',
        ]);
        $this->actingAsOwner();
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();

        $token = ActivationToken::where('lead_id', $leadId)->firstOrFail();
        $this->postJson('/api/v1/activate', [
            'token' => $token->token,
            'name' => 'Sami Owner',
            'email' => 'sami@'.$subdomain.'.test',
            'password' => 'StrongPass9!',
            'password_confirmation' => 'StrongPass9!',
        ])->assertOk();

        $tenant = Tenant::find($token->tenant_id);
        $this->createdTenantIds[] = (string) $tenant->id;

        return $tenant;
    }

    public function test_tenant_login_verifies_credentials_and_returns_central_token(): void
    {
        $tenant = $this->activatedTenant();
        $subdomain = $tenant->subdomain;

        $res = $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.superx.com',
            'username' => 'sami',
            'password' => 'StrongPass9!',
        ])->assertOk();

        $this->assertNotNull($res->json('token'));
        $this->assertSame($tenant->id, $res->json('business.id'));
        $this->assertSame('active', $res->json('business.status'));
        $this->assertFalse($res->json('user.is_platform_owner'));

        // Local dev: the same credentials work via the `{subdomain}.localhost`
        // host that the dev tenant URL uses (maps to the canonical domain).
        $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.localhost',
            'username' => 'sami',
            'password' => 'StrongPass9!',
        ])->assertOk()
            ->assertJsonPath('business.id', $tenant->id);

        // The owner actingAs() cached the sanctum guard — drop it so the
        // bearer token resolves to the tenant's mirror user.
        auth('sanctum')->forgetUser();

        // The returned token is a valid central Sanctum session on the mirror user.
        $this->withToken($res->json('token'))
            ->withHeader('X-Business-ID', $tenant->id)
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('business_id', $tenant->id);
    }

    public function test_tenant_login_rejects_wrong_credentials(): void
    {
        $tenant = $this->activatedTenant();
        $subdomain = $tenant->subdomain;

        $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.superx.com',
            'username' => 'sami',
            'password' => 'WrongPass1!',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);

        // Unknown host.
        $this->postJson('/api/v1/tenant-login', [
            'host' => 'nowhere.superx.com',
            'username' => 'x',
            'password' => 'StrongPass9!',
        ])->assertStatus(422)->assertJsonValidationErrors(['host']);
    }

    public function test_tenant_login_before_activation_returns_needs_activation(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();

        $token = ActivationToken::where('lead_id', $leadId)->firstOrFail();
        $this->createdTenantIds[] = (string) $token->tenant_id;

        $login = $this->postJson('/api/v1/tenant-login', [
            'host' => 'sami-superstore.superx.com',
            'username' => 'x',
            'password' => 'StrongPass9!',
        ])->assertStatus(422);
        $login->assertJsonPath('needs_activation', true);
        $login->assertJsonPath('business_name', 'Sami Superstore');
        $this->assertIsString($login->json('activation_url'));
    }

    public function test_user_created_via_users_api_can_login_through_tenant_subdomain(): void
    {
        $tenant = $this->activatedTenant();
        $businessId = (string) $tenant->business_id;
        $subdomain = $tenant->subdomain;

        // The activation wizard's root admin authenticates through the store
        // subdomain; that central token calls the Users API.
        $adminLogin = $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.superx.com',
            'username' => 'sami',
            'password' => 'StrongPass9!',
        ])->assertOk();

        auth('sanctum')->forgetUser();

        $created = $this->withToken($adminLogin->json('token'))
            ->withHeader('X-Business-ID', $businessId)
            ->postJson('/api/v1/users', [
                'name' => 'Qusai Cashier',
                'email' => 'qusai@superstore.test',
                'username' => 'qusai_cash',
                'password' => 'StrongPass9!',
                'password_confirmation' => 'StrongPass9!',
                'role' => 'staff',
                'is_active' => true,
            ])->assertCreated()
            ->assertJsonPath('username', 'qusai_cash')
            ->assertJsonPath('is_primary_admin', false);

        $userId = $created->json('id');

        // The account landed in the TENANT database with a HASHED password
        // (query-builder insert must never receive the plaintext).
        $tenantUser = null;
        $tenant->run(function () use ($businessId, &$tenantUser) {
            $tenantUser = DB::table('users')->where('business_id', $businessId)->where('username', 'qusai_cash')->first();
        });
        $this->assertNotNull($tenantUser, 'tenant users table row created');
        $this->assertTrue(Hash::check('StrongPass9!', $tenantUser->password));
        $this->assertFalse(Hash::check('qusai_cash', $tenantUser->password));
        $this->assertSame('staff', $tenantUser->role);
        $this->assertTrue((bool) $tenantUser->is_active);
        $this->assertFalse((bool) $tenantUser->is_primary_admin);

        // qusai_cash signs in through the store subdomain.
        $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.superx.com',
            'username' => 'qusai_cash',
            'password' => 'StrongPass9!',
        ])->assertOk()
            ->assertJsonPath('user.username', 'qusai_cash')
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonPath('business.id', $businessId);

        // Renaming the account + rotating its password stays in lock-step with
        // the tenant credential store (matched by the pre-update username).
        auth('sanctum')->forgetUser();
        $this->withToken($adminLogin->json('token'))
            ->withHeader('X-Business-ID', $businessId)
            ->putJson("/api/v1/users/{$userId}", [
                'username' => 'qusai_cash2',
                'password' => 'NewPass9!',
                'password_confirmation' => 'NewPass9!',
                'role' => 'cashier',
            ])->assertOk()
            ->assertJsonPath('username', 'qusai_cash2');

        $renamed = null;
        $oldStillThere = null;
        $tenant->run(function () use ($businessId, &$renamed, &$oldStillThere) {
            $renamed = DB::table('users')->where('business_id', $businessId)->where('username', 'qusai_cash2')->first();
            $oldStillThere = DB::table('users')->where('business_id', $businessId)->where('username', 'qusai_cash')->first();
        });
        $this->assertNotNull($renamed, 'tenant row renamed');
        $this->assertTrue(Hash::check('NewPass9!', $renamed->password));
        $this->assertNull($oldStillThere, 'pre-update username removed from tenant store');

        $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.superx.com',
            'username' => 'qusai_cash2',
            'password' => 'NewPass9!',
        ])->assertOk()
            ->assertJsonPath('user.role', 'cashier');

        $this->postJson('/api/v1/tenant-login', [
            'host' => $subdomain.'.superx.com',
            'username' => 'qusai_cash',
            'password' => 'StrongPass9!',
        ])->assertStatus(422);
    }

    public function test_same_username_allowed_across_different_tenants(): void
    {
        // Both tenants derive their root-admin handle `sami` from the email
        // local-part — per-tenant scoping must NOT bump the second one.
        $tenantA = $this->activatedTenant('sami-store-a');
        $tenantB = $this->activatedTenant('sami-store-b');

        $this->assertTrue(
            User::withoutBusiness()->where('business_id', $tenantA->business_id)->where('username', 'sami')->exists()
        );
        $this->assertTrue(
            User::withoutBusiness()->where('business_id', $tenantB->business_id)->where('username', 'sami')->exists()
        );

        $loginA = $this->postJson('/api/v1/tenant-login', [
            'host' => $tenantA->subdomain.'.superx.com',
            'username' => 'sami',
            'password' => 'StrongPass9!',
        ])->assertOk();
        auth('sanctum')->forgetUser();

        $this->withToken($loginA->json('token'))
            ->withHeader('X-Business-ID', $tenantA->business_id)
            ->postJson('/api/v1/users', [
                'name' => 'Qusai A',
                'email' => 'qusai@a.test',
                'username' => 'qusai_cash',
                'password' => 'StrongPass9!',
                'password_confirmation' => 'StrongPass9!',
                'role' => 'staff',
            ])->assertCreated();

        $loginB = $this->postJson('/api/v1/tenant-login', [
            'host' => $tenantB->subdomain.'.superx.com',
            'username' => 'sami',
            'password' => 'StrongPass9!',
        ])->assertOk();
        auth('sanctum')->forgetUser();

        // The SAME handle in a second tenant must not collide globally.
        $this->withToken($loginB->json('token'))
            ->withHeader('X-Business-ID', $tenantB->business_id)
            ->postJson('/api/v1/users', [
                'name' => 'Qusai B',
                'email' => 'qusai@b.test',
                'username' => 'qusai_cash',
                'password' => 'StrongPass9!',
                'password_confirmation' => 'StrongPass9!',
                'role' => 'cashier',
            ])->assertCreated();

        // Each signs in through its OWN subdomain with its OWN role.
        $this->postJson('/api/v1/tenant-login', [
            'host' => $tenantA->subdomain.'.superx.com',
            'username' => 'qusai_cash',
            'password' => 'StrongPass9!',
        ])->assertOk()->assertJsonPath('user.role', 'staff');

        $this->postJson('/api/v1/tenant-login', [
            'host' => $tenantB->subdomain.'.superx.com',
            'username' => 'qusai_cash',
            'password' => 'StrongPass9!',
        ])->assertOk()->assertJsonPath('user.role', 'cashier');
    }

    // ─── Central-domain login hardening ────────────────────────

    public function test_central_login_rejects_tenant_users_and_accepts_owner(): void
    {
        $this->activatedTenant();

        // A tenant user with CORRECT credentials is blocked on the central
        // domain — they must sign in through their store subdomain.
        $this->postJson('/api/v1/login', [
            'username' => 'sami',
            'password' => 'StrongPass9!',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Tenant accounts must log in through their dedicated store subdomain.');

        // Wrong credentials still report the generic invalid-credentials 422
        // (no user enumeration through the 403 path).
        $this->postJson('/api/v1/login', [
            'username' => 'sami',
            'password' => 'WrongPass1!',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);

        // The platform owner CAN authenticate centrally.
        $owner = User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'SuperX Owner',
            'username' => 'central-owner-check',
            'email' => 'central-owner@superx.test',
            'password' => Hash::make('OwnerPass1@'),
            'role' => 'superx_owner',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/login', [
            'username' => 'central-owner-check',
            'password' => 'OwnerPass1@',
        ])->assertOk()
            ->assertJsonPath('user.is_platform_owner', true)
            ->assertJsonPath('user.username', 'central-owner-check')
            ->assertJsonPath('business', null);
    }

    public function test_central_login_rejects_inactive_owner(): void
    {
        User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'Inactive Owner',
            'username' => 'inactive-owner-check',
            'email' => 'inactive-owner@superx.test',
            'password' => Hash::make('OwnerPass1@'),
            'role' => 'superx_owner',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/login', [
            'username' => 'inactive-owner-check',
            'password' => 'OwnerPass1@',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);
    }

    // ─── Subdomain rules ─────────────────────────────────────────

    public function test_provisioning_auto_slugs_a_subdomain_when_none_was_claimed(): void
    {
        $leadId = $this->submitLead(['subdomain' => null]);
        $this->actingAsOwner();

        $res = $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();

        $this->assertSame('sami-superstore.superx.com', $res->json('domain'));
        $this->createdTenantIds[] = $res->json('business_id');

        $lead = Lead::find($leadId);
        $this->assertSame('sami-superstore', $lead->subdomain);
    }

    // ─── Tenant directory details (owner portal) ─────────────────

    public function test_tenant_directory_shows_subdomain_domain_database_and_lead_owner_contact(): void
    {
        $leadId = $this->submitLead();
        $this->actingAsOwner();
        $res = $this->postJson("/api/v1/platform/leads/{$leadId}/approve")->assertCreated();
        $businessId = $res->json('business_id');
        $this->createdTenantIds[] = $businessId;

        // The tenant row in the owner-portal directory exposes the subdomain,
        // full domain, physical database name and — because the wizard hasn't
        // run yet — the originating lead's contact as the owner.
        $res = $this->getJson('/api/v1/platform/tenants')->assertOk();

        $tenant = collect($res->json('tenants'))->firstWhere('id', $businessId);
        $this->assertNotNull($tenant);
        $this->assertSame('sami-superstore', $tenant['subdomain']);
        $this->assertSame('sami-superstore.superx.com', $tenant['domain']);
        $this->assertSame('tenant'.$businessId, $tenant['database']);
        $this->assertSame('http://sami-superstore.localhost:3000', $tenant['store_url']);
        $this->assertSame('Sami Owner', $tenant['owner_contact']['name']);
        $this->assertSame('sami@superstore.test', $tenant['owner_contact']['email']);
        $this->assertSame('+962785555555', $tenant['owner_contact']['phone']);
        $this->assertSame('standard', $tenant['plan']);
        $this->assertSame('activating', $tenant['status']);
    }

    public function test_tenant_directory_owner_contact_still_prefers_primary_admin(): void
    {
        // A manually-provisioned tenant (PlatformTenantController::store) has a
        // central primary admin — the directory must show the admin, not a lead.
        $this->actingAsOwner();

        $created = $this->postJson('/api/v1/platform/tenants', [
            'name' => 'Manual Retail',
            'business_type_id' => $this->businessType->id,
            'admin_name' => 'Manual Admin',
            'admin_username' => 'manual_admin',
            'admin_email' => 'manual@retail.test',
            'admin_password' => 'StrongPass9!',
            'admin_password_confirmation' => 'StrongPass9!',
        ])->assertCreated();
        $businessId = $created->json('id');

        $tenant = collect($this->getJson('/api/v1/platform/tenants')->json('tenants'))
            ->firstWhere('id', $businessId);
        $this->assertNotNull($tenant);
        $this->assertNull($tenant['subdomain'], 'manual tenants have no stancl tenant row');
        $this->assertNull($tenant['database']);
        $this->assertNull($tenant['store_url']);
        $this->assertSame('Manual Admin', $tenant['owner_contact']['name']);
        $this->assertSame('manual@retail.test', $tenant['owner_contact']['email']);
    }

    // ─── DatabaseSeeder end-to-end (demo tenant provisioning) ───

    public function test_database_seeder_provisions_all_demo_stores_via_onboarding_pipeline(): void
    {
        // TenantSeeder runs FIRST so every demo store flows through the real
        // pipeline (central Business + tenant DB + activation) exactly like the
        // Owner Dashboard "Approve" button, and BusinessSeeder reuses the
        // central mirror users instead of creating duplicate admins.
        $this->artisan('db:seed');

        $this->assertSame(8, Tenant::count(), 'every demo store gets a stancl tenant');
        $this->assertSame(8, Domain::count(), 'every demo store gets one domain each');
        $this->assertSame(8, Lead::where('status', 'provisioned')->count());

        foreach (Tenant::all() as $tenant) {
            $business = Business::find($tenant->id);
            $this->assertNotNull($business, 'central Business mirror exists per tenant');
            $this->assertSame('active', $business->status);
            $this->assertSame('standard', $business->plan);
            $this->assertNotNull($business->settings['currency'] ?? null, 'activation persisted settings');

            $this->assertNotNull($tenant->activated_at, 'tenant marked activated');
            $this->assertSame(1, $tenant->domains()->count());

            // The physical tenant DB is seeded with the shop row + root admin.
            $seededShop = null;
            $seededAdmin = null;
            $tenant->run(function () use (&$seededShop, &$seededAdmin, $tenant) {
                $seededShop = DB::table('businesses')->where('id', $tenant->business_id)->first();
                $seededAdmin = DB::table('users')->where('business_id', $tenant->business_id)
                    ->where('is_primary_admin', true)->first();
            });
            $this->assertNotNull($seededShop, 'tenant DB carries the shop row');
            $this->assertSame('active', $seededShop->status);
            $this->assertNotNull($seededAdmin, 'tenant DB carries the root admin');
            $this->assertTrue(Hash::check('password', $seededAdmin->password));

            // One central mirror admin per store for subdomain login.
            $this->assertSame(1, User::withoutBusiness()->where('business_id', $business->id)->count());
        }

        $this->createdTenantIds = array_values(array_merge(
            $this->createdTenantIds,
            Tenant::pluck('id')->map(fn ($id) => (string) $id)->all(),
        ));

        // The owner-portal directory exposes every provisioned store.
        $this->actingAsOwner();
        $res = $this->getJson('/api/v1/platform/tenants')->assertOk();
        $tenants = $res->json('tenants');
        $this->assertCount(8, $tenants);
        foreach ($tenants as $tenant) {
            $this->assertNotNull($tenant['subdomain']);
            $this->assertNotNull($tenant['domain']);
            $this->assertNotNull($tenant['database']);
            $this->assertNotNull($tenant['store_url']);
            $this->assertSame('active', $tenant['status']);
        }

        // Tenant subdomain login works with the seeded credentials. The tenants
        // table has no real `subdomain` column (stancl keeps it in the JSONB
        // `data`), so the tenant for a demo store resolves by its central id —
        // i.e. the id of the mirror Business — exactly like the workflow tests.
        $retail = Tenant::find(Business::where('slug', 'super-retail')->firstOrFail()->id);
        $this->postJson('/api/v1/tenant-login', [
            'host' => 'super-retail.superx.com',
            'username' => 'retail_admin',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('business.id', $retail->id)
            ->assertJsonPath('business.slug', 'super-retail');
    }
}
