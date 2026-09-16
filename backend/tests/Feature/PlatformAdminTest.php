<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Gate Retail',
            'slug' => 'gate-retail',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Tenant Admin',
            'username' => 'tenant-admin',
            'email' => 'admin@tenant.test',
            'password' => Hash::make('Password1@'),
            'role' => 'admin',
            'is_primary_admin' => true,
        ]);
    }

    // ─── Lead capture (public B2B onboarding) ────────────────────

    public function test_public_lead_submission_stores_demo_request(): void
    {
        $this->postJson('/api/v1/leads', [
            'name' => 'Sami Owner',
            'business_name' => 'Sami Superstore',
            'business_type_id' => $this->businessType->id,
            'phone' => '+962 07 8555 5555',
            'city' => 'Amman',
        ])->assertCreated()
            ->assertJsonPath('lead.business_name', 'Sami Superstore');

        $lead = Lead::first();
        $this->assertSame('new', $lead->status);
        $this->assertSame('+962785555555', $lead->phone);
    }

    public function test_lead_requires_all_core_fields(): void
    {
        $this->postJson('/api/v1/leads', [])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'business_name', 'business_type_id', 'phone']);
    }

    public function test_self_service_registration_route_is_disabled(): void
    {
        $this->postJson('/api/v1/register-business', [
            'business_name' => 'Nope',
            'business_type_id' => $this->businessType->id,
        ])->assertStatus(404);

        $this->assertSame(0, Business::where('name', 'Nope')->count());
    }

    // ─── Portal secrecy & authorization ──────────────────────────

    private function actingAsOwner(): User
    {
        $owner = User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'SuperX Owner',
            'username' => 'superx-owner',
            'email' => 'owner@superx.test',
            'password' => Hash::make('OwnerPass1@'),
            'role' => 'superx_owner',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        return $owner;
    }

    public function test_platform_endpoints_reject_anonymous_requests(): void
    {
        $this->getJson('/api/v1/platform/tenants')->assertStatus(404);
        $this->postJson('/api/v1/platform/tenants', [])->assertStatus(404);
    }

    public function test_tenant_admin_cannot_access_platform_endpoints(): void
    {
        Sanctum::actingAs($this->admin);

        // 403 comes from auth:sanctum + IdentifyBusiness absence — the
        // platform group has no business middleware, so the owner guard
        // answers with its flat 404.
        $this->getJson('/api/v1/platform/tenants')->assertStatus(404);
    }

    public function test_owner_can_list_tenant_directory_with_details(): void
    {
        $this->business->update([
            'plan' => 'pro',
            'contact_phone' => '+962790000001',
            'city' => 'Amman',
            'subscription_starts_at' => now()->startOfDay(),
            'expires_at' => now()->addDays(30)->startOfDay(),
            'max_pos_registers' => 3,
        ]);

        $this->actingAsOwner();

        $res = $this->getJson('/api/v1/platform/tenants')->assertOk();

        $tenant = collect($res->json('tenants'))->firstWhere('id', $this->business->id);
        $this->assertNotNull($tenant);
        $this->assertSame('Gate Retail', $tenant['name']);
        $this->assertSame('supermarket', $tenant['business_type']['slug']);
        $this->assertSame('Tenant Admin', $tenant['owner_contact']['name']);
        $this->assertSame('admin@tenant.test', $tenant['owner_contact']['email']);
        $this->assertSame('+962790000001', $tenant['owner_contact']['phone']);
        $this->assertSame('pro', $tenant['plan']);
        $this->assertSame(1, $tenant['pos_terminals']['used']);
        $this->assertSame(3, $tenant['pos_terminals']['max']);
        $this->assertSame('active', $tenant['status']);

        $summary = $res->json('summary');
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['active']);
    }

    public function test_env_secret_header_grants_platform_access_without_user(): void
    {
        config(['superx.owner_secret' => 'hush-hush-key']);

        $this->getJson('/api/v1/platform/tenants', ['X-Owner-Secret' => 'wrong'])->assertStatus(404);
        $this->getJson('/api/v1/platform/tenants', ['X-Owner-Secret' => 'hush-hush-key'])->assertOk();
    }

    // ─── Tenant provisioning ─────────────────────────────────────

    public function test_owner_creates_tenant_with_admin_credentials_and_subscription(): void
    {
        $this->actingAsOwner();

        $payload = [
            'name' => 'Coffee Corner',
            'business_type_id' => $this->businessType->id,
            'plan' => 'enterprise',
            'contact_phone' => '+962788888888',
            'city' => 'Irbid',
            'subscription_starts_at' => now()->toDateString(),
            'expires_at' => now()->addDays(365)->toDateString(),
            'max_pos_registers' => 5,
            'admin_name' => 'Corner Owner',
            'admin_username' => 'corner_admin',
            'admin_email' => 'corner@coffee.test',
            'admin_password' => 'StrongPass9!',
            'admin_password_confirmation' => 'StrongPass9!',
        ];

        $res = $this->postJson('/api/v1/platform/tenants', $payload)->assertCreated();

        $tenantId = $res->json('id');
        $this->assertSame('active', $res->json('status'));
        $this->assertSame('enterprise', $res->json('plan'));
        $this->assertSame(5, $res->json('pos_terminals.max'));

        $tenant = Business::withoutGlobalScopes()->findOrFail($tenantId);
        $this->assertSame('active', $tenant->status);
        $this->assertEquals(now()->addDays(365)->toDateString(), $tenant->expires_at->toDateString());

        $createdAdmin = User::query()->withoutBusiness()->where('username', 'corner_admin')->first();
        $this->assertNotNull($createdAdmin);
        $this->assertTrue((bool) $createdAdmin->is_primary_admin);
        $this->assertSame($tenantId, $createdAdmin->business_id);
        $this->assertSame(5, Role::withoutBusiness()->where('business_id', $tenantId)->count(), 'system roles should be seeded');

        // The provisioned admin CANNOT authenticate via the central domain —
        // tenant accounts are restricted to their dedicated store subdomain.
        $login = $this->postJson('/api/v1/login', [
            'username' => 'corner_admin',
            'password' => 'StrongPass9!',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Tenant accounts must log in through their dedicated store subdomain.');
    }

    public function test_create_tenant_requires_strong_admin_password(): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/v1/platform/tenants', [
            'name' => 'Weak Pass Ltd',
            'business_type_id' => $this->businessType->id,
            'admin_name' => 'X',
            'admin_username' => 'weak-admin',
            'admin_email' => 'weak@x.test',
            'admin_password' => 'weakpass',
            'admin_password_confirmation' => 'weakpass',
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_password']);
    }

    // ─── Subscription management ─────────────────────────────────

    public function test_owner_updates_subscription_parameters_and_status(): void
    {
        $this->business->update(['expires_at' => now()->addYear()->startOfDay()]);
        $this->actingAsOwner();

        $newExpiry = now()->addMonths(6)->toDateString();

        $this->putJson("/api/v1/platform/tenants/{$this->business->id}", [
            'plan' => 'premium',
            'status' => 'suspended',
            'max_pos_registers' => 2,
            'expires_at' => $newExpiry,
        ])->assertOk()
            ->assertJsonPath('plan', 'premium')
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('pos_terminals.max', 2);

        $this->assertSame('suspended', $this->business->fresh()->status);
        $this->assertSame('premium', $this->business->fresh()->plan);
    }

    public function test_owner_can_reset_tenant_admin_credentials(): void
    {
        $this->actingAsOwner();

        $this->putJson("/api/v1/platform/tenants/{$this->business->id}", [
            'admin_password' => 'NewSecure7!',
            'admin_password_confirmation' => 'NewSecure7!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecure7!', $this->admin->fresh()->password));
    }

    // ─── Subscription enforcement (the tenant gate) ─────────────

    private function loginTenant(): string
    {
        // Central /login only authenticates platform owners — the tenant admin
        // is rejected 403 even with correct credentials. Mint the auth token
        // directly on the fixture admin for the API-level subscription tests.
        $this->postJson('/api/v1/login', [
            'username' => 'tenant-admin',
            'password' => 'Password1@',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Tenant accounts must log in through their dedicated store subdomain.');

        return $this->admin->createToken('auth-token')->plainTextToken;
    }

    public function test_expiring_soon_flag_appears_within_seven_days_of_expiry(): void
    {
        $this->business->update(['expires_at' => now()->addDays(5)->startOfDay()]);

        // Central login is owner-only — the tenant admin is rejected.
        $this->postJson('/api/v1/login', [
            'username' => 'tenant-admin',
            'password' => 'Password1@',
        ])->assertStatus(403);

        // Bootstrap carries the warning state.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/bootstrap', ['X-Business-ID' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('subscription.state', 'expiring_soon');
    }

    public function test_active_subscription_far_from_expiry_has_no_warning(): void
    {
        $this->business->update(['expires_at' => now()->addDays(60)->startOfDay()]);

        $this->postJson('/api/v1/login', [
            'username' => 'tenant-admin',
            'password' => 'Password1@',
        ])->assertStatus(403);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/bootstrap', ['X-Business-ID' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('subscription.state', 'active');
    }

    public function test_expired_subscription_hard_locks_business_apis_and_blocks_central_login(): void
    {
        $this->business->update(['expires_at' => now()->subDay()->startOfDay()]);

        $token = $this->loginTenant();
        $this->assertNotSame(null, $token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-ID', $this->business->id)
            ->getJson('/api/v1/bootstrap')
            ->assertStatus(403)
            ->assertJsonPath('code', 'subscription_expired');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-ID', $this->business->id)
            ->getJson('/api/v1/dashboard/stats')
            ->assertStatus(403);
    }

    public function test_suspended_subscription_is_blocked_too(): void
    {
        $this->business->update(['status' => 'suspended']);

        $token = $this->loginTenant();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-ID', $this->business->id)
            ->getJson('/api/v1/products')
            ->assertStatus(403)
            ->assertJsonPath('code', 'subscription_suspended');
    }

    public function test_restoring_subscription_unlocks_the_tenant(): void
    {
        $this->business->update(['expires_at' => now()->subDay()->startOfDay()]);
        $this->actingAsOwner();

        $this->putJson("/api/v1/platform/tenants/{$this->business->id}", [
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/products', ['X-Business-ID' => $this->business->id])->assertOk();
    }

    // ─── POS register cap ────────────────────────────────────────

    public function test_max_pos_registers_caps_new_pos_users(): void
    {
        $this->business->update(['max_pos_registers' => 1]);
        Sanctum::actingAs($this->admin); // admin holds pos.view → seat #1 consumed

        // A custom role WITHOUT POS access does not consume a seat.
        $seatlessRole = Role::withoutBusiness()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'slug' => 'backoffice',
            'name' => 'Back Office',
            'permissions' => ['crm.view', 'crm.create'],
            'is_system' => false,
        ]);

        User::create([
            'business_id' => $this->business->id,
            'name' => 'Back Office',
            'username' => 'no_pos_user',
            'email' => 'nopos@tenant.test',
            'password' => Hash::make('GoodPass1@x'),
            'role' => $seatlessRole->slug,
        ]);

        $this->postJson('/api/v1/users', [
            'name' => 'Extra Cashier',
            'username' => 'extra_cashier',
            'email' => 'extra@tenant.test',
            'password' => 'GoodPass1@x',
            'role' => 'cashier',
        ])->assertStatus(422)->assertJsonPath('code', 'pos_seat_limit_reached');

        // Raising the cap unblocks creation.
        $this->business->update(['max_pos_registers' => 2]);
        $this->postJson('/api/v1/users', [
            'name' => 'Extra Cashier',
            'username' => 'extra_cashier',
            'email' => 'extra@tenant.test',
            'password' => 'GoodPass1@x',
            'role' => 'cashier',
        ])->assertCreated();
    }
}
