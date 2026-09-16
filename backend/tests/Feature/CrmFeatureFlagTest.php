<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V1 CRM feature-flag contract.
 *
 * config('features.crm_enabled') defaults to false. When disabled the CRM-
 * exclusive API surface (/customers/segments/stats, /loyalty/*, /campaigns/*,
 * /delivery/webhook) must 404, the CRM nav node must disappear from
 * /bootstrap, and customers must fall back to plain records (no auto-
 * provisioned loyalty card / no generated loyalty_card_number). Core customer
 * CRUD, lookup and statements stay live regardless of the flag. Flipping the
 * flag back on must restore the whole surface, the nav node and the model
 * hooks — schema, controllers and relationships are preserved for V2.
 */
class CrmFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'sales', 'purchases', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'CRM Flag Retail',
            'slug' => 'crm-flag-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Flag Admin',
            'username' => 'flag-admin',
            'email' => 'flag@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function navigationLabels(): array
    {
        return collect($this->getJson('/api/v1/bootstrap')->assertOk()->json('navigation'))->pluck('label')->all();
    }

    public function test_crm_disabled_keeps_customer_workflows_and_404s_crm_surface(): void
    {
        config(['features.crm_enabled' => false]);

        // Core customer workflows stay live (Sales/POS/Accounting/AR parity).
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Ali Ahmed', 'phone' => '+962785555555'])
            ->assertStatus(201)
            ->json();
        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson("/api/v1/customers/{$customer['id']}/statement")->assertOk();
        $this->postJson('/api/v1/customers/lookup', ['phone' => '+962785555555'])->assertOk();

        // CRM-exclusive surface is gone (404, not 403).
        $this->getJson('/api/v1/customers/segments/stats')->assertNotFound();
        $this->getJson('/api/v1/loyalty/cards')->assertNotFound();
        $this->postJson('/api/v1/loyalty/cards', ['customer_id' => $customer['id']])->assertNotFound();
        $this->getJson('/api/v1/campaigns')->assertNotFound();
        $this->postJson('/api/v1/campaigns', [])->assertNotFound();
        $this->postJson('/api/v1/delivery/webhook', [])->assertNotFound();

        // Bootstrap hides the CRM nav node and reports the flag off.
        $bootstrap = $this->getJson('/api/v1/bootstrap')->assertOk()->json();
        $this->assertFalse($bootstrap['features']['crm_enabled']);
        $this->assertFalse($bootstrap['features']['loyalty_enabled']);
        $this->assertNotContains('CRM', $this->navigationLabels());

        // Created customers are plain records — no CRM side effects.
        $stored = Customer::findOrFail($customer['id']);
        $this->assertNull($stored->loyalty_card_number);
        $this->assertDatabaseMissing('loyalty_cards', ['customer_id' => $stored->id]);
    }

    public function test_customer_model_hooks_respect_crm_feature_flag(): void
    {
        config(['features.crm_enabled' => false]);

        $plain = Customer::create(['business_id' => $this->business->id, 'name' => 'Plain Customer']);
        $this->assertNull($plain->loyalty_card_number);
        $this->assertDatabaseMissing('loyalty_cards', ['customer_id' => $plain->id]);

        config(['features.crm_enabled' => true]);

        $enrolled = Customer::create(['business_id' => $this->business->id, 'name' => 'Enrolled Customer']);
        $this->assertNotNull($enrolled->loyalty_card_number);

        $card = LoyaltyCard::where('customer_id', $enrolled->id)->firstOrFail();
        $this->assertSame($enrolled->loyalty_card_number, $card->card_number);
        $this->assertSame('bronze', $card->tier);
        $this->assertTrue((bool) $card->is_active);
    }

    public function test_crm_enabled_restores_loyalty_campaign_surface_and_nav(): void
    {
        config(['features.crm_enabled' => true]);

        $customer = $this->postJson('/api/v1/customers', ['name' => 'Happy Path', 'phone' => '+962785555555'])->assertStatus(201)->json();
        $this->assertDatabaseHas('loyalty_cards', ['customer_id' => $customer['id']]);

        $this->getJson('/api/v1/customers/segments/stats')->assertOk();
        $this->getJson('/api/v1/loyalty/cards')->assertOk();
        $this->getJson('/api/v1/campaigns')->assertOk();
        // Route-level gate passed (422 rather than 404) proves admin reached
        // validators on the re-enabled surface.
        $this->postJson('/api/v1/campaigns', [])->assertStatus(422);

        $bootstrap = $this->getJson('/api/v1/bootstrap')->assertOk()->json();
        $this->assertTrue($bootstrap['features']['crm_enabled']);
        $this->assertContains('CRM', $this->navigationLabels());

        $crmNode = collect($bootstrap['navigation'])->firstWhere('label', 'CRM');
        $this->assertSame('/crm/customers', $crmNode['children'][0]['route']);
        $this->assertSame('/crm/campaigns', $crmNode['children'][1]['route']);
    }
}
