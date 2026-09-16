<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises the CRM-exclusive surface (loyalty endpoints,
        // tier upgrades, card provisioning), which is feature-gated behind
        // config('features.crm_enabled') in V1.
        config(['features.crm_enabled' => true]);

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Loyalty Retail',
            'slug' => 'loyalty-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Loyalty User',
            'username' => 'loyalty-user',
            'email' => 'loyalty@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_customer_create_normalizes_phone_to_e164(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Ali Ahmed',
            'email' => 'ali@example.com',
            'phone' => '+962 0785555555',
        ]);

        $response->assertStatus(201);
        $this->assertSame('+962785555555', $response->json('phone'));

        // "00" international prefix and missing "+" are normalized too.
        $this->postJson('/api/v1/customers', [
            'name' => 'Sara',
            'phone' => '00962795551111',
        ])->assertStatus(201);
        $this->assertSame('+962795551111', Customer::where('name', 'Sara')->value('phone'));

        $this->postJson('/api/v1/customers', [
            'name' => 'Omar',
            'phone' => '0786662222',
        ])->assertStatus(201);
        $this->assertSame('+962786662222', Customer::where('name', 'Omar')->value('phone'));
    }

    public function test_customer_create_rejects_invalid_phone(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Bad Phone',
            'phone' => '+96212345678',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('customers', ['name' => 'Bad Phone']);
    }

    public function test_customer_create_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Bad Email',
            'email' => 'plainaddress',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_customer_create_generates_unique_loyalty_card_and_legacy_card(): void
    {
        $first = $this->postJson('/api/v1/customers', ['name' => 'Card One', 'phone' => '+962790000001']);
        $second = $this->postJson('/api/v1/customers', ['name' => 'Card Two', 'phone' => '+962790000002']);

        $first->assertStatus(201);
        $second->assertStatus(201);

        $firstNumber = $first->json('loyalty_card_number');
        $secondNumber = $second->json('loyalty_card_number');

        $this->assertMatchesRegularExpression('/^LOY-\d{12}$/', $firstNumber);
        $this->assertMatchesRegularExpression('/^LOY-\d{12}$/', $secondNumber);
        $this->assertNotSame($firstNumber, $secondNumber);

        $firstCustomer = Customer::findOrFail($first->json('id'));
        $this->assertDatabaseHas('loyalty_cards', [
            'business_id' => $this->business->id,
            'customer_id' => $firstCustomer->id,
            'card_number' => $firstNumber,
            'points_balance' => 0,
            'tier' => 'bronze',
        ]);
    }

    public function test_customer_index_search_finds_by_loyalty_card_number(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Searchable',
        ]);

        $this->getJson('/api/v1/customers?search='.$customer->loyalty_card_number)
            ->assertOk()
            ->assertJsonPath('data.0.id', $customer->id);
    }

    public function test_customer_lookup_by_card_number_phone_or_name(): void
    {
        $this->postJson('/api/v1/customers', [
            'name' => 'Lookup Me',
            'phone' => '+962785555555',
        ])->assertStatus(201);

        $customer = Customer::where('name', 'Lookup Me')->first();

        $this->postJson('/api/v1/customers/lookup', ['phone' => '0785555555'])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id);

        // Loyalty card number lookup.
        $this->postJson('/api/v1/customers/lookup', ['phone' => $customer->loyalty_card_number])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id);

        // Name lookup.
        $this->postJson('/api/v1/customers/lookup', ['phone' => 'Lookup Me'])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id);
    }

    public function test_loyalty_lookup_by_card_number_or_normalized_phone(): void
    {
        $this->postJson('/api/v1/customers', [
            'name' => 'Loyalty Lookup',
            'phone' => '+962785555555',
        ])->assertStatus(201);

        $customer = Customer::where('name', 'Loyalty Lookup')->first();
        $card = LoyaltyCard::where('customer_id', $customer->id)->firstOrFail();

        // Card number lookup.
        $this->postJson('/api/v1/loyalty/lookup', ['phone' => $card->card_number])
            ->assertOk()
            ->assertJsonPath('id', $card->id);

        // Exact E.164 phone lookup.
        $this->postJson('/api/v1/loyalty/lookup', ['phone' => '+962785555555'])
            ->assertOk()
            ->assertJsonPath('id', $card->id);

        // Normalized national-format phone lookup resolves the same customer.
        $this->postJson('/api/v1/loyalty/lookup', ['phone' => '0785555555'])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id);
    }

    public function test_paid_invoice_earns_points_on_customer_and_card(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Earning Customer',
        ]);
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $response = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0],
            ],
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        $response->assertStatus(201);
        $this->assertSame(10.0, (float) $response->json('net_amount'));

        $customer->refresh();
        $this->assertSame(10, $customer->loyalty_points_balance);
        $this->assertSame('bronze', $customer->tier_level);

        $card = LoyaltyCard::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(10, $card->points_balance);
        $this->assertSame(10, (int) $card->total_points_earned);
        $this->assertSame(10.0, (float) $card->total_spend);

        $this->assertDatabaseHas('loyalty_transactions', [
            'business_id' => $this->business->id,
            'loyalty_card_id' => $card->id,
            'type' => 'earn',
            'points' => 10,
            'invoice_id' => $response->json('id'),
        ]);
    }

    public function test_unpaid_invoice_does_not_earn_points(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'No Earn',
        ]);
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 10],
            ],
            'status' => 'draft',
            'payment_status' => 'unpaid',
        ])->assertStatus(201);

        $this->assertSame(0, $customer->fresh()->loyalty_points_balance);
        $this->assertDatabaseMissing('loyalty_transactions', ['type' => 'earn']);
    }

    public function test_paid_invoice_upgrades_tier_from_total_spend(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Tier Up',
        ]);
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $card = LoyaltyCard::where('customer_id', $customer->id)->firstOrFail();
        $card->update(['total_spend' => 600]);

        $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 10],
            ],
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ])->assertStatus(201);

        $this->assertSame('silver', $card->fresh()->tier);
        $this->assertSame('silver', $customer->fresh()->tier_level);
    }

    public function test_loyalty_redeem_syncs_customer_balance(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Redeemer',
        ]);
        $card = LoyaltyCard::where('customer_id', $customer->id)->firstOrFail();
        $card->update(['points_balance' => 100]);
        $customer->update(['loyalty_points_balance' => 100]);

        $this->postJson('/api/v1/loyalty/transactions', [
            'loyalty_card_id' => $card->id,
            'type' => 'redeem',
            'points' => 40,
            'description' => 'POS redemption',
        ])->assertStatus(201);

        $this->assertSame(60, $card->fresh()->points_balance);
        $this->assertSame(60, $customer->fresh()->loyalty_points_balance);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id,
            'type' => 'redeem',
            'points' => 40,
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Loyalty Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 0,
            'has_batch' => true,
            'is_active' => true,
            'stock_quantity' => 0,
        ], $overrides));
    }

    private function makeBatch(Product $product, float $quantity): ProductBatch
    {
        return ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'LB-'.strtoupper(Str::random(6)),
            'quantity' => $quantity,
            'quantity_sold' => 0,
            'quantity_returned' => 0,
            'cost_per_unit' => 4,
            'total_cost' => round($quantity * 4, 2),
            'received_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);
    }
}
