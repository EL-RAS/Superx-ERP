<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsersManagementTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Users Retail',
            'slug' => 'users-retail',
            'status' => 'active',
        ]);
    }

    private function makeUser(array $overrides = [], string $role = 'staff'): User
    {
        return User::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'test_user_'.Str::random(6),
            'email' => 'user-'.Str::random(6).'@example.com',
            'password' => Hash::make('Str0ng!Pass'),
            'role' => $role,
        ], $overrides));
    }

    private function actingAsAdmin(bool $primary = true): User
    {
        $user = $this->makeUser(['is_primary_admin' => $primary], 'admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Staff',
            'email' => 'staff-'.Str::random(6).'@example.com',
            'username' => 'staff_'.Str::random(6),
            'password' => 'Str0ng!Pass',
            'role' => 'staff',
        ], $overrides);
    }

    public function test_store_rejects_weak_password(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users', $this->validPayload(['password' => 'password']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/users', $this->validPayload(['password' => 'Password1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/users', $this->validPayload(['password' => 'Password!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/users', $this->validPayload(['password' => 'p@ssword1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_store_creates_active_user_by_default(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/users', $this->validPayload())
            ->assertStatus(201)
            ->json();

        $this->assertTrue($response['is_active']);
        $this->assertFalse($response['is_primary_admin']);
    }

    public function test_store_accepts_inactive_status(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/users', $this->validPayload(['is_active' => false]))
            ->assertStatus(201)
            ->json();

        $this->assertFalse($response['is_active']);
    }

    public function test_store_rejects_invalid_email(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/users', $this->validPayload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_update_rejects_weak_password(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeUser();

        $this->putJson("/api/v1/users/{$target->id}", ['password' => 'weak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_tenant_user_login_rejected_on_central_domain(): void
    {
        $user = $this->makeUser(['username' => 'central-tenant-check', 'password' => Hash::make('Str0ng!Pass')]);

        $this->postJson('/api/v1/login', [
            'username' => 'central-tenant-check',
            'password' => 'Str0ng!Pass',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Tenant accounts must log in through their dedicated store subdomain.');
    }

    public function test_inactive_owner_cannot_login(): void
    {
        User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'Inactive Owner',
            'username' => 'inactive-owner',
            'email' => 'inactive-owner@superx.test',
            'password' => Hash::make('Str0ng!Pass'),
            'role' => 'superx_owner',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/login', [
            'username' => 'inactive-owner',
            'password' => 'Str0ng!Pass',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);
    }

    public function test_active_owner_can_login(): void
    {
        User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'SuperX Owner',
            'username' => 'active-owner',
            'email' => 'active-owner@superx.test',
            'password' => Hash::make('Str0ng!Pass'),
            'role' => 'superx_owner',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/login', [
            'username' => 'active-owner',
            'password' => 'Str0ng!Pass',
        ])->assertOk()
            ->assertJsonPath('user.is_platform_owner', true)
            ->assertJsonPath('user.username', 'active-owner')
            ->assertJsonPath('business', null);
    }

    public function test_primary_admin_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $primary = $this->makeUser(['is_primary_admin' => true], 'admin');

        $this->deleteJson("/api/v1/users/{$primary->id}")->assertForbidden();
        $this->assertNotNull($primary->fresh());
    }

    public function test_user_cannot_delete_own_account(): void
    {
        $admin = $this->actingAsAdmin(false);

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
        $this->assertNotNull($admin->fresh());
    }

    public function test_normal_user_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeUser();

        $this->deleteJson("/api/v1/users/{$target->id}")->assertOk();
        $this->assertNull($target->fresh());
    }

    public function test_primary_admin_cannot_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $primary = $this->makeUser(['is_primary_admin' => true], 'admin');

        $this->putJson("/api/v1/users/{$primary->id}", ['is_active' => false])->assertStatus(422);
        $this->assertTrue((bool) $primary->fresh()->is_active);
    }

    public function test_index_returns_status_columns_and_filters(): void
    {
        $this->actingAsAdmin();
        $this->makeUser(['is_active' => false]);

        $response = $this->getJson('/api/v1/users')->assertOk();

        $this->assertArrayHasKey('is_active', $response->json('data.0'));
        $this->assertArrayHasKey('is_primary_admin', $response->json('data.0'));

        $this->getJson('/api/v1/users?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }
}
