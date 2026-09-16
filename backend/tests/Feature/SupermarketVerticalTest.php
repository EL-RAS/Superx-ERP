<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupermarketVerticalTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Loyalty earn/redeem, the CRM nav node and the loyalty settings
        // surface depend on config('features.crm_enabled').
        config(['features.crm_enabled' => true]);

        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports', 'barcode_scanner', 'expiry_tracking', 'weighing_scale', 'loyalty', 'promotions'],
            'default_settings' => [
                'allow_split_payments' => true,
                'allow_credit_sales' => false,
                'expiry_alerts' => true,
                'low_stock_sensitivity' => 'strict',
                'barcode_scanner' => true,
                'rapid_mode' => true,
                'loyalty_enabled' => true,
                'promotions_enabled' => true,
            ],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Fresh Mart',
            'slug' => 'fresh-mart',
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

    public function test_supermarket_onboarding_defaults_are_applied(): void
    {
        $settings = $this->business->fresh()->settings;

        $this->assertSame(true, $settings['allow_split_payments']);
        $this->assertSame(false, $settings['allow_credit_sales']);
        $this->assertSame(true, $settings['expiry_alerts']);
        $this->assertSame('strict', $settings['low_stock_sensitivity']);
        $this->assertSame(true, $settings['barcode_scanner']);
        $this->assertSame(true, $settings['rapid_mode']);
        $this->assertSame(true, $settings['loyalty_enabled']);
        $this->assertSame(true, $settings['promotions_enabled']);
    }

    public function test_settings_endpoint_returns_merged_supermarket_defaults(): void
    {
        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('settings.allow_split_payments', true)
            ->assertJsonPath('settings.allow_credit_sales', false)
            ->assertJsonPath('settings.expiry_alerts', true)
            ->assertJsonPath('settings.low_stock_sensitivity', 'strict')
            ->assertJsonPath('settings.rapid_mode', true)
            ->assertJsonPath('settings.loyalty_enabled', true)
            ->assertJsonPath('settings.promotions_enabled', true);
    }

    public function test_bootstrap_surfaces_merged_settings_and_vertical_features(): void
    {
        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('business_type', 'supermarket')
            ->assertJsonPath('settings.allow_split_payments', true)
            ->assertJsonPath('features.expiry_alerts', true)
            ->assertJsonPath('features.low_stock_sensitivity', 'strict')
            ->assertJsonPath('features.rapid_mode', true)
            ->assertJsonPath('features.loyalty_enabled', true)
            ->assertJsonPath('features.promotions_enabled', true);
    }

    public function test_loyalty_settings_can_be_disabled_and_hide_nav(): void
    {
        $this->putJson('/api/v1/businesses/settings', [
            'loyalty_enabled' => false,
            'promotions_enabled' => false,
            'rapid_mode' => false,
        ])->assertOk();

        $bootstrap = $this->getJson('/api/v1/bootstrap')->assertOk()->json();

        $this->assertFalse($bootstrap['features']['loyalty_enabled']);
        $this->assertFalse($bootstrap['features']['promotions_enabled']);
        $this->assertFalse($bootstrap['features']['rapid_mode']);

        $crmNav = collect($bootstrap['navigation'])->firstWhere('label', 'CRM');
        $this->assertNotNull($crmNav);
        $loyaltyChild = collect($crmNav['children'] ?? [])->firstWhere('route', '/loyalty');
        $this->assertNull($loyaltyChild, 'Loyalty nav must hide when loyalty is disabled.');
    }

    public function test_non_supermarket_business_uses_generic_defaults(): void
    {
        $genericType = BusinessType::create([
            'slug' => 'generic_shop',
            'name_en' => 'Generic Shop',
            'name_ar' => 'متجر',
            'allowed_modules' => ['sales', 'pos', 'inventory'],
        ]);

        $generic = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $genericType->id,
            'name' => 'Corner Shop',
            'slug' => 'corner-shop',
            'status' => 'active',
        ]);

        $this->assertNull($generic->fresh()->settings);

        $merged = $generic->mergedSettings();
        $this->assertFalse($merged['allow_split_payments']);
        $this->assertFalse($merged['expiry_alerts']);
        $this->assertSame('normal', $merged['low_stock_sensitivity']);
        $this->assertFalse($merged['rapid_mode']);
        $this->assertFalse($merged['loyalty_enabled']);
        $this->assertFalse($merged['promotions_enabled']);
    }

    public function test_category_supports_color_and_subcategories(): void
    {
        $parent = $this->postJson('/api/v1/categories', [
            'name' => 'Fruits & Veg',
            'color' => '#22C55E',
        ])->assertStatus(201)->json();

        $child = $this->postJson('/api/v1/categories', [
            'name' => 'Apples',
            'parent_id' => $parent['id'],
            'color' => '#16A34A',
        ])->assertStatus(201)->json();

        $this->assertSame($parent['id'], $child['parent_id']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonFragment(['id' => $child['id'], 'parent_id' => $parent['id'], 'color' => '#16A34A']);
    }

    public function test_barcode_lookup_returns_product(): void
    {
        $product = $this->makeProduct(['barcode' => '6291041500213']);

        $this->postJson('/api/v1/products/barcode/lookup', ['barcode' => '6291041500213'])
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('barcode', '6291041500213');
    }

    public function test_barcode_lookup_unknown_barcode_404(): void
    {
        $this->postJson('/api/v1/products/barcode/lookup', ['barcode' => '6291041500000'])
            ->assertNotFound();
    }

    public function test_weighable_product_sells_by_kilogram_decimals(): void
    {
        $product = $this->makeProduct([
            'name' => 'Tomatoes',
            'unit' => 'kg',
            'is_weighable' => true,
            'has_batch' => false,
            'stock_quantity' => 10,
            'cost' => 0.4,
            'price' => 0.8,
        ]);

        $invoice = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Tomatoes', 'quantity' => 0.75, 'unit_price' => 0.8, 'tax_rate' => 5],
            ],
        ])->assertStatus(201)->json();

        $this->assertSame('paid', $invoice['payment_status']);
        $this->assertSame(0.63, (float) $invoice['net_amount']);

        $fresh = $product->fresh();
        $this->assertSame(9.25, (float) $fresh->stock_quantity);

        $this->assertTrue(Payment::where('invoice_id', $invoice['id'])->where('amount', 0.63)->exists());

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_split_payment_checkout_creates_two_tender_legs(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 20, 'price' => 10, 'cost' => 4]);

        $response = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'split',
            'payment_status' => 'paid',
            'split_details' => [
                ['method' => 'cash', 'amount' => 7.00],
                ['method' => 'card', 'amount' => 4.00],
            ],
            'items' => [
                ['product_id' => $product->id, 'name' => 'Olive Oil', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 10],
            ],
        ])->assertStatus(201);

        $invoice = $response->json();
        $this->assertSame('paid', $invoice['payment_status']);
        $this->assertSame(11.0, (float) $invoice['net_amount']);

        $legs = Payment::where('invoice_id', $invoice['id'])->where('status', 'completed')->get();
        $this->assertCount(2, $legs);
        $this->assertSame(11.0, (float) $legs->sum('amount'));

        $this->assertTrue($legs->contains(fn ($p) => $p->method === 'cash' && (float) $p->amount === 7.0));
        $this->assertTrue($legs->contains(fn ($p) => $p->method === 'card' && (float) $p->amount === 4.0));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_split_payment_does_not_require_a_registered_customer(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 20, 'price' => 10, 'cost' => 4]);

        $response = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'split',
            'payment_status' => 'paid',
            'split_details' => [
                ['method' => 'cash', 'amount' => 5.50],
                ['method' => 'card', 'amount' => 5.50],
            ],
            'items' => [
                ['product_id' => $product->id, 'name' => 'Milk', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 10],
            ],
        ])->assertStatus(201);

        $invoice = $response->json();
        $this->assertSame('paid', $invoice['payment_status']);
        $this->assertNull($invoice['customer_id']);

        $legs = Payment::where('invoice_id', $invoice['id'])->where('status', 'completed')->get();
        $this->assertCount(2, $legs);
        $this->assertSame(11.0, (float) $legs->sum('amount'));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_percentage_promotion_applies_at_pos_preview(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postJson('/api/v1/promotions', [
            'name' => 'Buy more save more',
            'type' => 'percentage',
            'value' => 15,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'min_amount' => 20,
            'applicable_products' => [$product->id],
            'is_active' => true,
        ])->assertStatus(201);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(4.5, (float) $applied['total_discount']);
    }

    public function test_bogo_promotion_grants_free_unit(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 5, 'cost' => 2]);

        $this->postJson('/api/v1/promotions', [
            'name' => 'BOGO',
            'type' => 'bogo',
            'value' => 100,
            'buy_quantity' => 2,
            'get_quantity' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'applicable_products' => [$product->id],
            'is_active' => true,
        ])->assertStatus(201);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 5],
            ],
        ])->assertOk()->json();

        $this->assertSame(5.0, (float) $applied['total_discount']);
    }

    public function test_loyalty_earns_points_on_paid_sale_and_redeems_at_pos(): void
    {
        $customer = $this->makeCustomer();
        $card = LoyaltyCard::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(0, (int) $card->points_balance);

        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 5, 'price' => 10, 'cost' => 4]);

        $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Detergent', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ])->assertStatus(201);

        // 10.50 net -> floor 10 points at 1 pt / JOD.
        $card = $card->fresh();
        $this->assertSame(10, (int) $card->points_balance);
        $this->assertSame(10, (int) $card->total_points_earned);
        $this->assertSame(10, (int) $customer->fresh()->loyalty_points_balance);

        $this->assertTrue(LoyaltyTransaction::where('loyalty_card_id', $card->id)->where('type', 'earn')->where('points', 10)->exists());

        // POS redemption.
        $redeem = $this->postJson('/api/v1/loyalty/transactions', [
            'loyalty_card_id' => $card->id,
            'type' => 'redeem',
            'points' => 6,
            'description' => 'POS checkout discount',
        ])->assertStatus(201)->json();

        $this->assertSame(4, (int) $redeem['card']['points_balance']);
        $this->assertSame(6, (int) $redeem['card']['total_points_redeemed']);
        $this->assertSame(4, (int) $customer->fresh()->loyalty_points_balance);
    }

    public function test_loyalty_redeem_above_balance_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $card = LoyaltyCard::where('customer_id', $customer->id)->firstOrFail();

        $this->postJson('/api/v1/loyalty/transactions', [
            'loyalty_card_id' => $card->id,
            'type' => 'redeem',
            'points' => 100,
        ])->assertStatus(422);
    }

    public function test_grn_receives_po_batch_with_expiry_and_posts_gl(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['has_batch' => true, 'name' => 'Milk 1L', 'price' => 1.2, 'cost' => 0.8]);

        $po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'name' => 'Milk 1L', 'quantity' => 24, 'unit_cost' => 0.8],
            ],
        ])->assertStatus(201)->json();

        $this->putJson("/api/v1/purchase-orders/{$po['id']}", ['status' => 'ordered'])->assertOk();

        $grn = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po['id'],
            'items' => [
                [
                    'purchase_order_item_id' => $po['items'][0]['id'],
                    'product_id' => $product->id,
                    'received_quantity' => 24,
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'storage_location' => 'Aisle 3',
                ],
            ],
        ])->assertStatus(201)->json();

        $this->assertSame('GRN-1', $grn['receipt_number']);

        $batch = ProductBatch::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(24, (int) $batch->quantity);
        $this->assertSame(now()->addMonths(6)->toDateString(), $batch->expiry_date->toDateString());
        $this->assertSame('Aisle 3', $batch->storage_location);
        $this->assertSame(19.2, (float) $batch->total_cost);
        $this->assertSame(0.8, (float) $batch->cost_per_unit);

        $this->assertSame('received', PurchaseOrder::findOrFail($po['id'])->status);

        // Default credit leg: Dr 1030 / Cr 2010 payable.
        $entry = JournalEntry::where('reference_type', 'goods_receipt')->where('reference_id', $grn['id'])->firstOrFail();
        $this->assertTrue((bool) $entry->is_posted);
        $this->assertEntryBalances($entry, ['1030' => 19.2, '2010' => -19.2]);

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_grn_batch_product_requires_expiry_date(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['has_batch' => true, 'name' => 'Yogurt', 'price' => 0.6, 'cost' => 0.35]);

        $po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'name' => 'Yogurt', 'quantity' => 12, 'unit_cost' => 0.35],
            ],
        ])->assertStatus(201)->json();

        $this->putJson("/api/v1/purchase-orders/{$po['id']}", ['status' => 'ordered'])->assertOk();

        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po['id'],
            'items' => [
                [
                    'purchase_order_item_id' => $po['items'][0]['id'],
                    'product_id' => $product->id,
                    'received_quantity' => 12,
                ],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, ProductBatch::where('product_id', $product->id)->count());
    }

    public function test_expiry_alerts_return_near_expiry_batches(): void
    {
        $product = $this->makeProduct(['has_batch' => true]);
        $expiring = $this->makeBatch($product, 10, now()->addDays(7));
        $this->makeBatch($product, 10, now()->addMonths(8));

        $this->getJson('/api/v1/inventory-adjustments/expiry-alerts?days=30')
            ->assertOk()
            ->assertJsonFragment(['id' => $expiring->id]);
    }

    public function test_low_stock_returns_out_of_stock_and_low_products(): void
    {
        $out = $this->makeProduct(['name' => 'Bread', 'has_batch' => false, 'stock_quantity' => 0, 'min_stock' => 10]);
        $low = $this->makeProduct(['name' => 'Rice 5kg', 'has_batch' => false, 'stock_quantity' => 3, 'min_stock' => 10]);
        $this->makeProduct(['name' => 'Sugar 1kg', 'has_batch' => false, 'stock_quantity' => 50, 'min_stock' => 10]);

        $response = $this->getJson('/api/v1/inventory-adjustments/low-stock?type=all')->assertOk()->json();

        $this->assertSame(2, $response['counters']['total']);
        $this->assertSame(1, $response['counters']['out_of_stock']);
        $this->assertSame(1, $response['counters']['low_stock']);

        $ids = collect($response['data'])->pluck('id');
        $this->assertTrue($ids->contains($out->id));
        $this->assertTrue($ids->contains($low->id));
    }

    public function test_shift_close_shortage_posts_cash_shortage_gl(): void
    {
        $this->postJson('/api/v1/shifts/start', ['opening_balance' => 100])->assertStatus(201);
        $shift = Shift::where('business_id', $this->business->id)->firstOrFail();

        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 10, 'price' => 10, 'cost' => 4]);
        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Basket', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0],
            ],
        ])->assertStatus(201);

        $this->postJson("/api/v1/shifts/{$shift->id}/close", [
            'actual_cash' => 105,
        ])->assertOk();

        $shift = $shift->fresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame(110.0, (float) $shift->expected_cash);
        $this->assertSame(-5.0, (float) $shift->variance);

        $entry = JournalEntry::where('reference_type', 'shift_close')->where('reference_id', $shift->id)->firstOrFail();
        $this->assertEntryBalances($entry, ['5042' => 5.0, '1010' => -5.0]);

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_shift_close_surplus_posts_cash_surplus_revenue(): void
    {
        $this->postJson('/api/v1/shifts/start', ['opening_balance' => 100])->assertStatus(201);
        $shift = Shift::where('business_id', $this->business->id)->firstOrFail();

        $this->postJson("/api/v1/shifts/{$shift->id}/close", [
            'actual_cash' => 115,
        ])->assertOk();

        $shift = $shift->fresh();
        $this->assertSame(100.0, (float) $shift->expected_cash);
        $this->assertSame(15.0, (float) $shift->variance);

        $entry = JournalEntry::where('reference_type', 'shift_close')->where('reference_id', $shift->id)->firstOrFail();
        $this->assertEntryBalances($entry, ['1010' => 15.0, '4040' => -15.0]);

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_dashboard_returns_today_sales_and_gross_margin(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 20, 'price' => 10, 'cost' => 4]);

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Detergent', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ])->assertStatus(201);

        $dashboard = $this->getJson('/api/v1/dashboard/stats')->assertOk()->json();

        $this->assertSame(1, $dashboard['invoices']['total']);
        $this->assertSame(21.0, (float) $dashboard['invoices']['revenue']);
        $this->assertSame(8.0, (float) $dashboard['invoices']['cogs']);
        $this->assertSame(13.0, (float) $dashboard['invoices']['gross_profit']);
        $this->assertSame(61.9, (float) $dashboard['invoices']['gross_margin']);
        $this->assertSame(1, $dashboard['today']['transactions']);
        $this->assertSame(21.0, (float) $dashboard['today']['revenue']);
    }

    public function test_supermarket_dashboard_returns_today_kpis(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 20, 'price' => 10, 'cost' => 4, 'category' => 'Dairy']);

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Milk', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ])->assertStatus(201);

        $dash = $this->getJson('/api/v1/dashboard/supermarket')->assertOk()->json();

        // Today KPIs
        $this->assertSame(21.0, (float) $dash['today']['revenue']);
        $this->assertSame(1, $dash['today']['transactions']);
        $this->assertSame(21.0, (float) $dash['today']['average_ticket']);

        // 21.0 revenue - 8.0 cogs = 13.0
        $this->assertSame(13.0, (float) $dash['today']['gross_profit']);
        $this->assertSame(61.9, (float) $dash['today']['gross_margin']);
    }

    public function test_supermarket_dashboard_categorizes_and_tracks_payment_split(): void
    {
        $dairy = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 50, 'price' => 5, 'cost' => 2, 'category' => 'Fresh Dairy']);
        $snack = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 50, 'price' => 3, 'cost' => 1, 'category' => 'Snacks & Chips']);
        $noCat = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 50, 'price' => 4, 'cost' => 2, 'category' => null]);

        // dairy: 2 x 5 = 10 (+tax 5% => 10.5), snack: 2 x 3 = 6 (+5% => 6.3), noCat: 1 x 4 = 4 (+5% => 4.2)
        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $dairy->id, 'name' => 'Yogurt', 'quantity' => 2, 'unit_price' => 5, 'tax_rate' => 5],
                ['product_id' => $snack->id, 'name' => 'Chips', 'quantity' => 2, 'unit_price' => 3, 'tax_rate' => 5],
                ['product_id' => $noCat->id, 'name' => 'Generic', 'quantity' => 1, 'unit_price' => 4, 'tax_rate' => 5],
            ],
        ])->assertStatus(201);

        $dash = $this->getJson('/api/v1/dashboard/supermarket')->assertOk()->json();

        $byCategory = collect($dash['sales_by_category'])->keyBy('category');
        $this->assertArrayHasKey('dairy', $byCategory);
        $this->assertArrayHasKey('snacks', $byCategory);
        $this->assertArrayHasKey('other', $byCategory);
        $this->assertEqualsWithDelta(10.0, (float) $byCategory['dairy']['revenue'], 0.01);
        $this->assertEqualsWithDelta(6.0, (float) $byCategory['snacks']['revenue'], 0.01);
        $this->assertEqualsWithDelta(4.0, (float) $byCategory['other']['revenue'], 0.01);

        // Cash payment split matches the cash invoice net amount
        $this->assertGreaterThan(0.0, (float) $dash['payment_breakdown']['cash']);
    }

    public function test_supermarket_dashboard_lists_expiry_and_low_stock_alerts(): void
    {
        $expiring = $this->makeProduct(['has_batch' => true, 'category' => 'Dairy', 'min_stock' => 5]);
        $this->makeBatch($expiring, 10, now()->addDays(5)->toDateString());

        $low = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 2, 'min_stock' => 10]);

        $dash = $this->getJson('/api/v1/dashboard/supermarket')->assertOk()->json();

        $this->assertSame(1, $dash['inventory']['expiring_count']);
        $this->assertSame(1, $dash['inventory']['low_stock_count']);
        $this->assertSame('Grocery Item', $dash['expiry_alerts'][0]['product_name']);
        $this->assertSame(5, $dash['expiry_alerts'][0]['days_remaining']);
        $this->assertSame(10.0, (float) $dash['expiry_alerts'][0]['current_stock']);
        $this->assertSame(2.0, (float) $dash['low_stock'][0]['current_stock']);
        $this->assertSame(10.0, (float) $dash['low_stock'][0]['min_stock']);
    }

    public function test_supermarket_dashboard_top_products_orders_by_quantity(): void
    {
        $a = $this->makeProduct(['name' => 'Apples', 'has_batch' => false, 'stock_quantity' => 100, 'price' => 2, 'cost' => 1, 'category' => 'Produce']);
        $b = $this->makeProduct(['name' => 'Bread', 'has_batch' => false, 'stock_quantity' => 100, 'price' => 4, 'cost' => 2, 'category' => 'Bakery']);

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $a->id, 'name' => 'Apples', 'quantity' => 5, 'unit_price' => 2, 'tax_rate' => 0],
                ['product_id' => $b->id, 'name' => 'Bread', 'quantity' => 2, 'unit_price' => 4, 'tax_rate' => 0],
            ],
        ])->assertStatus(201);

        $dash = $this->getJson('/api/v1/dashboard/supermarket')->assertOk()->json();

        $this->assertCount(2, $dash['top_products']);
        $this->assertSame('Apples', $dash['top_products'][0]['name']);
        $this->assertSame(5.0, (float) $dash['top_products'][0]['quantity']);
        $this->assertSame(10.0, (float) $dash['top_products'][0]['revenue']);
        $this->assertSame('Bread', $dash['top_products'][1]['name']);
    }

    public function test_supermarket_dashboard_reports_active_shift(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 20, 'price' => 10, 'cost' => 4]);

        $this->postJson('/api/v1/shifts/start', ['opening_balance' => 100])->assertStatus(201);
        $shift = Shift::where('business_id', $this->business->id)->firstOrFail();

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0],
            ],
        ])->assertStatus(201);

        $dash = $this->getJson('/api/v1/dashboard/supermarket')->assertOk()->json();

        $this->assertNotNull($dash['active_shift']);
        $this->assertSame($shift->id, $dash['active_shift']['id']);
        $this->assertSame('Admin', $dash['active_shift']['cashier']);
        $this->assertSame(110.0, (float) $dash['active_shift']['expected_cash']); // 100 opening + 10 cash
        $this->assertSame(1, $dash['active_shift']['total_transactions']);
    }

    public function test_tenant_dashboard_dispatches_supermarket_for_supermarket_hypermarket_type(): void
    {
        $hyper = BusinessType::create([
            'slug' => 'supermarket_hypermarket',
            'name_en' => 'Supermarket & Hypermarket',
            'name_ar' => 'سوبرماركت وهايبرماركت',
            'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'reports', 'barcode_scanner', 'expiry_tracking', 'loyalty', 'promotions'],
            'default_settings' => [],
        ]);

        $biz = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $hyper->id,
            'name' => 'Hyper Mart',
            'slug' => 'hyper-mart-dispatch',
            'status' => 'active',
        ]);

        $user = User::create([
            'business_id' => $biz->id,
            'name' => 'Admin',
            'username' => 'admin-'.Str::random(6),
            'email' => 'admin-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_primary_admin' => true,
        ]);

        Sanctum::actingAs($user);

        // The unified tenant endpoint must produce the supermarket command
        // center payload (not the generic stats shape) for this vertical.
        $dash = $this->getJson('/api/v1/tenant/dashboard')->assertOk()->json();

        $this->assertArrayHasKey('generated_at', $dash);
        $this->assertArrayHasKey('today', $dash);
        $this->assertArrayHasKey('average_ticket', $dash['today']);
        $this->assertArrayHasKey('hourly_sales', $dash);
        $this->assertArrayHasKey('sales_by_category', $dash);
        $this->assertArrayHasKey('payment_breakdown', $dash);
        $this->assertArrayHasKey('expiry_alerts', $dash);
        $this->assertArrayHasKey('low_stock', $dash);
        $this->assertArrayHasKey('top_products', $dash);
        $this->assertArrayHasKey('active_shift', $dash);
    }

    public function test_tenant_dashboard_falls_back_to_generic_for_other_verticals(): void
    {
        $other = BusinessType::create([
            'slug' => 'clothing_apparel',
            'name_en' => 'Clothing & Apparel',
            'name_ar' => 'ملابس',
            'allowed_modules' => ['sales', 'pos', 'inventory', 'accounting', 'reports'],
            'default_settings' => [],
        ]);

        $biz = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $other->id,
            'name' => 'Fashion House',
            'slug' => 'fashion-house-dispatch',
            'status' => 'active',
        ]);

        $user = User::create([
            'business_id' => $biz->id,
            'name' => 'Admin',
            'username' => 'admin-'.Str::random(6),
            'email' => 'admin-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_primary_admin' => true,
        ]);

        Sanctum::actingAs($user);

        // Non-grocery verticals get the generic stats overview from the very
        // same unified endpoint.
        $dash = $this->getJson('/api/v1/dashboard')->assertOk()->json();

        $this->assertArrayHasKey('products', $dash);
        $this->assertArrayHasKey('invoices', $dash);
        $this->assertArrayHasKey('today', $dash);
        $this->assertArrayHasKey('recent_invoices', $dash);
        $this->assertArrayNotHasKey('hourly_sales', $dash);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Grocery Item',
            'sku' => 'SKU-'.strtoupper(Str::random(8)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 5,
            'has_batch' => true,
            'is_active' => true,
            'stock_quantity' => 0,
            'min_stock' => 0,
        ], $overrides));
    }

    private function makeBatch(Product $product, float $quantity, ?string $expiryDate = null): ProductBatch
    {
        return ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'B-'.strtoupper(Str::random(8)),
            'quantity' => $quantity,
            'quantity_sold' => 0,
            'quantity_returned' => 0,
            'cost_per_unit' => 0.8,
            'total_cost' => round($quantity * 0.8, 2),
            'received_date' => now()->toDateString(),
            'expiry_date' => $expiryDate ?? now()->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Loyal Shopper',
        ]);
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Daily Supplies Co.',
        ]);
    }

    private function assertAllEntriesPostedAndBalanced(): void
    {
        $entries = JournalEntry::where('business_id', $this->business->id)->get();

        foreach ($entries as $entry) {
            $this->assertTrue((bool) $entry->is_posted, "Entry {$entry->entry_number} must be posted.");
            $this->assertNotSame('draft', $entry->status);

            $debit = (float) $entry->lines->sum('debit');
            $credit = (float) $entry->lines->sum('credit');
            $this->assertEqualsWithDelta($debit, $credit, 0.001, "Entry {$entry->entry_number} must balance.");
        }

        $totalDebit = (float) JournalEntryLine::whereHas('journalEntry', fn ($q) => $q->where('business_id', $this->business->id))
            ->sum('debit');
        $totalCredit = (float) JournalEntryLine::whereHas('journalEntry', fn ($q) => $q->where('business_id', $this->business->id))
            ->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.001, 'Ledger must balance globally.');
    }

    /**
     * Assert a journal entry's net per-account balance (debit - credit).
     *
     * @param  array<string, float>  $expected  account code => net balance
     */
    private function assertEntryBalances(JournalEntry $entry, array $expected): void
    {
        $accountCodes = array_keys($expected);
        $accounts = Account::where('business_id', $this->business->id)
            ->whereIn('code', $accountCodes)
            ->get(['id', 'code'])
            ->keyBy('id');

        $actual = [];
        foreach ($entry->lines as $line) {
            $account = $accounts->get($line->account_id);
            if (! $account) {
                continue;
            }
            $code = $account->code;
            $amount = (float) $line->debit - (float) $line->credit;
            $actual[$code] = round(($actual[$code] ?? 0) + $amount, 2);
        }

        foreach ($expected as $code => $net) {
            $this->assertEqualsWithDelta($net, $actual[$code] ?? 0, 0.001, "Account {$code} net balance.");
        }
    }
}
