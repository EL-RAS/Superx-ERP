<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // The cashier-navigation test asserts the CRM nav node; the node is
        // only emitted when config('features.crm_enabled') is true.
        config(['features.crm_enabled' => true]);

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Rbac Retail',
            'slug' => 'rbac-retail',
            'status' => 'active',
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => ucfirst($role).' User',
            'username' => $role.'-user',
            'email' => $role.'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_business_creation_seeds_system_roles(): void
    {
        $roles = Role::query()->get();

        $this->assertCount(5, $roles);
        $this->assertEqualsCanonicalizing(
            ['admin', 'manager', 'accountant', 'staff', 'cashier'],
            $roles->pluck('slug')->all()
        );
        $this->assertTrue($roles->every(fn (Role $role) => $role->is_system));
        $this->assertSame(RbacService::allPermissionKeys(), Role::where('slug', 'admin')->value('permissions'));
    }

    public function test_admin_bypasses_permission_checks(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/accounts')->assertOk();
        $this->getJson('/api/v1/users')->assertOk();
        $this->getJson('/api/v1/reports/trial-balance')->assertOk();
    }

    public function test_cashier_can_access_pos_and_sales_reads(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/invoices')->assertOk();
        $this->getJson('/api/v1/dashboard/stats')->assertOk();
        // 200 with a newly created walk-in proves the POST lookup passed the
        // permission gate (403 vs 200).
        $this->postJson('/api/v1/customers/lookup', ['phone' => '+962785555555'])
            ->assertOk()
            ->assertJsonPath('newly_created', true);
    }

    public function test_cashier_can_create_invoices(): void
    {
        $this->actingAsRole('cashier');

        // Invalid payload proves the route passed the permission gate (403 vs 422).
        $this->postJson('/api/v1/invoices', [])->assertStatus(422);
    }

    public function test_editing_or_voiding_invoices_requires_sales_lock(): void
    {
        $admin = $this->actingAsRole('admin');

        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'user_id' => $admin->id,
            'invoice_number' => 'INV-100',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 10,
            'total_amount' => 10,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'net_amount' => 10,
            'currency' => 'JOD',
        ]);

        // Admin has the `sales.lock` special permission automatically.
        $this->assertTrue($admin->hasPermission('sales.lock'));
        $this->assertTrue(in_array('sales.lock', RbacService::allPermissionKeys(), true));

        // A real update proves the admin passed the gate (200 vs 403).
        $this->patchJson("/api/v1/invoices/{$invoice->id}", ['notes' => 'admin edit'])->assertOk();

        $this->actingAsRole('manager');
        // Manager has sales.edit/delete but NOT sales.lock -> strictly locked.
        $this->patchJson("/api/v1/invoices/{$invoice->id}", ['items' => []])->assertForbidden();
        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertForbidden();

        $this->actingAsRole('cashier');
        $this->patchJson("/api/v1/invoices/{$invoice->id}", ['items' => []])->assertForbidden();
        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertForbidden();
    }

    public function test_cashier_denied_inventory_writes(): void
    {
        $this->actingAsRole('cashier');

        $this->postJson('/api/v1/products', [])->assertForbidden();
        $this->getJson('/api/v1/warehouses')->assertForbidden();
        $this->getJson('/api/v1/product-batches')->assertForbidden();
        $this->postJson('/api/v1/product-batches/sell-fefo', [])->assertForbidden();
    }

    public function test_cashier_denied_accounting_purchases_users_settings_reports(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->getJson('/api/v1/journal-entries')->assertForbidden();
        $this->getJson('/api/v1/suppliers')->assertForbidden();
        $this->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/roles/matrix')->assertForbidden();
        $this->getJson('/api/v1/reports/trial-balance')->assertForbidden();
        $this->putJson('/api/v1/businesses/settings', [])->assertForbidden();
    }

    public function test_manager_accounting_is_view_only(): void
    {
        $this->actingAsRole('manager');

        $this->getJson('/api/v1/accounts')->assertOk();
        $this->postJson('/api/v1/accounts', [])->assertForbidden();
        $this->getJson('/api/v1/reports/trial-balance')->assertOk();
    }

    public function test_accountant_can_manage_accounting_but_not_users(): void
    {
        $this->actingAsRole('accountant');

        $this->getJson('/api/v1/accounts')->assertOk();
        $this->postJson('/api/v1/accounts', [])->assertStatus(422);
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_staff_denied_accounting_and_users(): void
    {
        $this->actingAsRole('staff');

        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/categories', [])->assertStatus(422);
    }

    public function test_role_matrix_endpoint(): void
    {
        $this->actingAsRole('admin');

        $response = $this->getJson('/api/v1/roles/matrix')->assertOk();

        $this->assertNotEmpty($response->json('modules'));
        $this->assertSame(['view', 'create', 'edit', 'delete'], collect($response->json('actions'))->pluck('key')->all());
        $this->assertCount(5, $response->json('roles'));
    }

    public function test_custom_role_crud_and_permissions(): void
    {
        $this->actingAsRole('admin');

        $created = $this->postJson('/api/v1/roles', [
            'name' => 'Warehouse Clerk',
            'description' => 'Handles inventory only',
            'permissions' => ['inventory.view', 'inventory.create'],
        ])->assertStatus(201)->json();

        $this->assertSame('warehouse-clerk', $created['slug']);
        $this->assertSame(['inventory.view', 'inventory.create'], $created['permissions']);

        $this->putJson("/api/v1/roles/{$created['id']}/permissions", [
            'permissions' => ['inventory.view', 'inventory.edit'],
        ])->assertOk();

        $this->assertSame(['inventory.view', 'inventory.edit'], Role::find($created['id'])->permissions);

        $this->deleteJson("/api/v1/roles/{$created['id']}")->assertOk();
        $this->assertNull(Role::find($created['id']));
    }

    public function test_invalid_permission_key_rejected(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/roles', [
            'name' => 'Broken Role',
            'permissions' => ['not.a.real.key'],
        ])->assertStatus(422);

        $role = Role::where('slug', 'cashier')->first();

        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['bogus'],
        ])->assertStatus(422);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $this->actingAsRole('admin');

        $adminRole = Role::where('slug', 'manager')->first();

        $this->deleteJson("/api/v1/roles/{$adminRole->id}")->assertStatus(422);
    }

    public function test_assigned_custom_role_cannot_be_deleted(): void
    {
        $this->actingAsRole('admin');

        $role = $this->postJson('/api/v1/roles', [
            'name' => 'Night Shift',
            'permissions' => ['pos.view'],
        ])->assertStatus(201)->json();

        User::create([
            'business_id' => $this->business->id,
            'name' => 'Night User',
            'username' => 'night-user',
            'email' => 'night@example.com',
            'password' => Hash::make('password'),
            'role' => $role['slug'],
        ]);

        $this->deleteJson("/api/v1/roles/{$role['id']}")->assertStatus(422);
    }

    public function test_user_store_requires_valid_role(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/users', [
            'name' => 'Ghost User',
            'username' => 'ghost-user',
            'password' => 'password',
            'role' => 'nonexistent-role',
        ])->assertStatus(422);
    }

    public function test_edited_role_permissions_apply_to_user(): void
    {
        $cashier = $this->actingAsRole('cashier');

        $this->assertTrue($cashier->hasPermission('pos.view'));
        $this->assertFalse($cashier->hasPermission('inventory.view'));

        $role = Role::where('slug', 'cashier')->first();
        $role->update(['permissions' => array_values(array_diff($role->permissions, ['pos.view', 'pos.create']))]);

        $this->assertFalse($cashier->fresh()->hasPermission('pos.view'));
    }

    public function test_bootstrap_filters_navigation_for_cashier(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->getJson('/api/v1/bootstrap')->assertOk();

        $labels = collect($response->json('navigation'))->pluck('label')->all();
        $this->assertContains('Dashboard', $labels);
        $this->assertContains('POS', $labels);
        $this->assertContains('Sales', $labels);
        $this->assertContains('CRM', $labels);
        $this->assertNotContains('Inventory', $labels);
        $this->assertNotContains('Accounting', $labels);
        $this->assertNotContains('Users & Roles', $labels);
        $this->assertNotContains('Purchases', $labels);
        $this->assertNotContains('Reports', $labels);
        $this->assertNotContains('Settings', $labels);

        $this->assertContains('pos.view', $response->json('user_permissions'));
        $this->assertNotContains('inventory.view', $response->json('user_permissions'));
    }

    public function test_bootstrap_includes_accounting_for_admin(): void
    {
        $this->actingAsRole('admin');

        $labels = collect($this->getJson('/api/v1/bootstrap')->assertOk()->json('navigation'))->pluck('label')->all();
        $this->assertContains('Accounting', $labels);
    }

    public function test_bootstrap_pos_menu_lists_shifts_for_admin_only(): void
    {
        $posChildren = fn (string $role) => collect(
            $this->getJson('/api/v1/bootstrap')->assertOk()->json('navigation')
        )->firstWhere('label', 'POS')['children'] ?? [];

        $admin = $this->actingAsRole('admin');
        $adminChildren = $posChildren('admin');
        $this->assertContains('pos.shifts', $admin->permissionKeys());
        $this->assertContains('/pos', array_column($adminChildren, 'route'));
        $this->assertContains('/pos/shifts', array_column($adminChildren, 'route'));

        $this->actingAsRole('cashier');
        $cashierChildren = $posChildren('cashier');
        $this->assertNotContains('/pos/shifts', array_column($cashierChildren, 'route'));
        $this->assertContains('/pos', array_column($cashierChildren, 'route'));
    }

    public function test_shift_management_endpoints_admin_only(): void
    {
        $this->actingAsRole('admin');
        $this->getJson('/api/v1/shifts')->assertOk();
        $this->getJson('/api/v1/z-reports')->assertOk();

        $this->actingAsRole('cashier');
        $this->getJson('/api/v1/shifts')->assertForbidden();
        $this->getJson('/api/v1/z-reports')->assertForbidden();
    }

    public function test_last_closed_shift_pos_scoped_and_user_scoped(): void
    {
        $cashier = $this->actingAsRole('cashier');

        $this->postJson('/api/v1/shifts/start', ['opening_balance' => 50])->assertStatus(201);
        $shift = Shift::where('user_id', $cashier->id)->firstOrFail();
        $this->postJson("/api/v1/shifts/{$shift->id}/close", ['actual_cash' => 50])->assertOk();

        // Cashiers can fetch their own last closed shift via the POS endpoint.
        $this->getJson('/api/v1/shifts/last-closed')->assertOk()->assertJsonPath('id', $shift->id);

        // Another POS user sees null (no closed shift of their own).
        $this->actingAsRole('manager');
        $this->getJson('/api/v1/shifts/last-closed')->assertOk()->assertExactJson([]);

        // The business-wide history stays admin-only.
        $this->getJson('/api/v1/shifts')->assertForbidden();
    }
}
