<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Collection;
use App\Models\Shift;
use App\Models\User;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditCleanupTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'clothing_apparel',
            'name_en' => 'Clothing & Apparel',
            'name_ar' => 'ملابس',
            'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports', 'barcode_scanner', 'variant_matrix', 'loyalty', 'collections', 'returns_exchanges'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Fashion House',
            'slug' => 'fashion-house',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'username' => 'admin-'.Str::random(6),
            'email' => 'admin-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_primary_admin' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_reorder_level_column_was_dropped_from_products(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'reorder_level'));
    }

    public function test_collections_crud_round_trip(): void
    {
        $created = $this->postJson('/api/v1/collections', [
            'name' => 'Summer 2026',
            'season' => 'summer',
            'year' => '2026',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'is_active' => true,
        ])->assertStatus(201)->json();

        $this->assertSame('Summer 2026', $created['name']);
        $this->assertSame('summer', $created['season']);
        $this->assertSame(0, $created['products_count']);

        $this->getJson('/api/v1/collections')
            ->assertOk()
            ->assertJsonFragment(['id' => $created['id'], 'name' => 'Summer 2026', 'products_count' => 0]);

        $this->getJson("/api/v1/collections/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('id', $created['id']);

        $updated = $this->putJson("/api/v1/collections/{$created['id']}", [
            'name' => 'Summer 2026 Edition',
            'is_active' => false,
        ])->assertOk()->json();

        $this->assertSame('Summer 2026 Edition', $updated['name']);
        $this->assertSame(false, $updated['is_active']);

        $this->deleteJson("/api/v1/collections/{$created['id']}")->assertOk();

        $this->assertDatabaseMissing('collections', ['id' => $created['id']]);
    }

    public function test_collections_validation_requires_name_season_and_year(): void
    {
        $this->postJson('/api/v1/collections', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/collections', ['name' => 'No Season'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['season', 'year']);

        $this->postJson('/api/v1/collections', ['name' => 'Valid', 'season' => 'summer', 'year' => '2026', 'end_date' => '2026-01-01', 'start_date' => '2026-02-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_collections_are_tenant_scoped(): void
    {
        $otherType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['sales', 'pos', 'inventory'],
        ]);

        $other = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $otherType->id,
            'name' => 'Other Biz',
            'slug' => 'other-biz',
            'status' => 'active',
        ]);

        Collection::create([
            'business_id' => $other->id,
            'name' => 'Foreign Collection',
            'season' => 'spring',
            'year' => 2026,
        ]);

        $this->getJson('/api/v1/collections')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_z_reports_index_and_show_are_routed(): void
    {
        $shift = Shift::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'shift_number' => 'SH-0001',
            'status' => 'closed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $report = ZReport::create([
            'business_id' => $this->business->id,
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'report_number' => 'Z-0001',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'opening_balance' => 100,
            'actual_cash' => 150,
            'total_sales' => 50,
            'total_transactions' => 3,
        ]);

        $this->getJson('/api/v1/z-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonPath('data.0.report_number', 'Z-0001');

        $this->getJson("/api/v1/z-reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('id', $report->id);
    }
}
