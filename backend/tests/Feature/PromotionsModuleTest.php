<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionsModuleTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'promo-store',
            'name_en' => 'Promo Store',
            'name_ar' => 'متجر العروض',
            'allowed_modules' => ['sales', 'pos', 'promotions'],
            'default_settings' => ['promotions_enabled' => true],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Promo Mart',
            'slug' => 'promo-mart',
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

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Promo Item',
            'sku' => 'SKU-'.strtoupper(Str::random(8)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 0,
            'has_batch' => true,
            'is_active' => true,
            'stock_quantity' => 0,
            'min_stock' => 0,
        ], $overrides));
    }

    private function postPromotion(array $overrides = []): array
    {
        return $this->postJson('/api/v1/promotions', array_merge([
            'name' => 'Deal',
            'type' => 'percentage',
            'value' => 10,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'is_active' => true,
        ], $overrides))->assertStatus(201)->json();
    }

    public function test_multi_buy_promotion_discounts_bundle_pricing(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 5, 'cost' => 2]);

        $this->postPromotion([
            'name' => '3 for 10',
            'type' => 'multi_buy',
            'value' => 10,
            'min_quantity' => 3,
            'applicable_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 5],
            ],
        ])->assertOk()->json();

        $this->assertSame(5.0, (float) $applied['total_discount']);
        $this->assertSame(10.0, (float) $applied['cart_total']);

        $appliedFour = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 5],
            ],
        ])->assertOk()->json();

        $this->assertSame(5.0, (float) $appliedFour['total_discount']);
        $this->assertSame(15.0, (float) $appliedFour['cart_total']);
    }

    public function test_category_promotion_discounts_whole_category(): void
    {
        $category = Category::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Snacks',
            'sort_order' => 0,
        ]);

        $product = $this->makeProduct([
            'has_batch' => false,
            'category_id' => $category->id,
            'price' => 8,
            'cost' => 3,
        ]);

        $this->postPromotion([
            'name' => 'Snacks 20% off',
            'type' => 'category',
            'value' => 20,
            'category_id' => $category->id,
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 8],
            ],
        ])->assertOk()->json();

        $this->assertSame(3.2, (float) $applied['total_discount']);
    }

    public function test_promotion_apply_skipped_when_promotions_disabled(): void
    {
        $this->business->settings = ['promotions_enabled' => false];
        $this->business->save();

        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Ignored',
            'type' => 'percentage',
            'value' => 50,
            'applicable_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame([], $applied['applied_promotions']);
        $this->assertSame(0.0, (float) $applied['total_discount']);
        $this->assertSame(10.0, (float) $applied['cart_total']);
    }

    public function test_product_sale_price_and_is_on_sale_roundtrip(): void
    {
        $created = $this->postJson('/api/v1/products', [
            'name' => 'Sale Item',
            'sku' => 'SALE-'.strtoupper(Str::random(6)),
            'price' => 100,
            'sale_price' => 79.9,
            'is_on_sale' => true,
            'cost' => 40,
            'tax_rate' => 0,
        ])->assertStatus(201)->json();

        $this->assertSame(79.9, (float) $created['sale_price']);
        $this->assertSame(true, $created['is_on_sale']);
        $this->assertSame(79.9, (float) $created['effective_price']);

        $updated = $this->putJson("/api/v1/products/{$created['id']}", [
            'sale_price' => null,
            'is_on_sale' => false,
        ])->assertOk()->json();

        $this->assertSame(null, $updated['sale_price']);
        $this->assertSame(false, $updated['is_on_sale']);
        $this->assertSame(100.0, (float) $updated['effective_price']);
    }

    public function test_product_is_on_sale_without_price_falls_back_to_price(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 50, 'cost' => 20]);

        $updated = $this->putJson("/api/v1/products/{$product->id}", [
            'is_on_sale' => true,
            'sale_price' => 0,
        ])->assertOk()->json();

        $this->assertSame(false, $updated['is_on_sale']);
        $this->assertSame(50.0, (float) $updated['effective_price']);
    }

    public function test_promotion_category_id_and_multi_buy_validation(): void
    {
        $category = Category::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Dairy',
            'sort_order' => 0,
        ]);

        $promo = $this->postPromotion([
            'name' => 'Dairy deal',
            'type' => 'category',
            'value' => 15,
            'category_id' => $category->id,
        ]);

        $this->assertSame($category->id, (int) $promo['category_id']);

        $multi = $this->postPromotion([
            'name' => 'Bundle',
            'type' => 'multi_buy',
            'value' => 12,
            'min_quantity' => 4,
        ]);

        $this->assertSame('multi_buy', $multi['type']);
    }

    public function test_percentage_promotion_respects_min_quantity_per_line(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Min qty 3',
            'type' => 'percentage',
            'value' => 20,
            'min_quantity' => 3,
            'applicable_products' => [$product->id],
        ]);

        $below = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(0.0, (float) $below['total_discount']);

        $meets = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(6.0, (float) $meets['total_discount']);
    }

    public function test_bogo_promotion_gives_free_items_per_group(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Buy 2 Get 1',
            'type' => 'bogo',
            'value' => 0,
            'buy_quantity' => 2,
            'get_quantity' => 1,
            'applicable_products' => [$product->id],
        ]);

        $three = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(10.0, (float) $three['total_discount']);
        $this->assertSame(20.0, (float) $three['cart_total']);

        $five = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(10.0, (float) $five['total_discount']);
    }

    public function test_bundle_promotion_uses_combo_products_and_discount_value(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 8, 'cost' => 3]);

        $this->postPromotion([
            'name' => 'Combo deal',
            'type' => 'bundle',
            'value' => 5,
            'discount_value' => 5,
            'combo_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 8],
            ],
        ])->assertOk()->json();

        $this->assertSame(5.0, (float) $applied['total_discount']);
        $this->assertSame(11.0, (float) $applied['cart_total']);
    }

    public function test_fixed_promotion_offsets_whole_cart_and_is_capped(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Flat JOD5',
            'type' => 'fixed',
            'value' => 5,
            'applicable_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(5.0, (float) $applied['total_discount']);
        $this->assertSame(25.0, (float) $applied['cart_total']);

        $oversized = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 4],
            ],
        ])->assertOk()->json();

        $this->assertSame(4.0, (float) $oversized['total_discount']);
        $this->assertSame(0.0, (float) $oversized['cart_total']);
    }

    public function test_happy_hour_applies_only_inside_time_window(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 01:30:00'));

        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Overnight happy hour',
            'type' => 'happy_hour',
            'value' => 20,
            'happy_hour_start' => '22:00',
            'happy_hour_end' => '02:00',
            'applicable_products' => [$product->id],
        ]);

        $this->postPromotion([
            'name' => 'Wrong window',
            'type' => 'happy_hour',
            'value' => 50,
            'happy_hour_start' => '03:00',
            'happy_hour_end' => '05:00',
            'applicable_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(4.0, (float) $applied['total_discount']);
        $this->assertCount(1, $applied['applied_promotions']);
        $this->assertSame('Overnight happy hour', $applied['applied_promotions'][0]['name']);
        $this->assertSame(16.0, (float) $applied['cart_total']);
    }

    public function test_happy_hour_inactive_outside_window_and_when_unset(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 14:00:00'));

        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Morning only',
            'type' => 'happy_hour',
            'value' => 20,
            'happy_hour_start' => '08:00',
            'happy_hour_end' => '09:00',
            'applicable_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        $this->assertSame(0.0, (float) $applied['total_discount']);
        $this->assertSame([], $applied['applied_promotions']);
    }

    public function test_best_deal_does_not_stack_promotions_on_same_item(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $bogo = $this->postPromotion([
            'name' => 'Buy 2 Get 1',
            'type' => 'bogo',
            'value' => 0,
            'buy_quantity' => 2,
            'get_quantity' => 1,
            'applicable_products' => [$product->id],
        ]);

        $this->postPromotion([
            'name' => 'Half price',
            'type' => 'percentage',
            'value' => 50,
            'applicable_products' => [$product->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        // bogo would give 10 (one free unit), percentage 50% gives 15 -> single best deal only.
        $this->assertSame(15.0, (float) $applied['total_discount']);
        $this->assertCount(1, $applied['applied_promotions']);
        $this->assertNotSame($bogo['id'], $applied['applied_promotions'][0]['id']);
        $this->assertSame('Half price', $applied['applied_promotions'][0]['name']);
        $this->assertSame(15.0, (float) $applied['cart_total']);
    }

    public function test_best_deal_resolves_independently_per_cart_line(): void
    {
        $productA = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4, 'sku' => 'SKU-A']);
        $productB = $this->makeProduct(['has_batch' => false, 'price' => 20, 'cost' => 5, 'sku' => 'SKU-B']);

        $promoA = $this->postPromotion([
            'name' => 'A deal',
            'type' => 'percentage',
            'value' => 10,
            'applicable_products' => [$productA->id],
        ]);

        $promoB = $this->postPromotion([
            'name' => 'B deal',
            'type' => 'percentage',
            'value' => 25,
            'applicable_products' => [$productB->id],
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 10],
                ['product_id' => $productB->id, 'quantity' => 2, 'unit_price' => 20],
            ],
        ])->assertOk()->json();

        // Line A: 10% of 10 = 1. Line B: 25% of 40 = 10. Both promotions win their own line.
        $this->assertSame(11.0, (float) $applied['total_discount']);
        $this->assertSame(39.0, (float) $applied['cart_total']);
        $this->assertCount(2, $applied['applied_promotions']);

        $names = array_column($applied['applied_promotions'], 'name');
        sort($names);
        $this->assertSame(['A deal', 'B deal'], $names);

        $appliedIds = array_map(fn ($p) => (float) $p['id'], $applied['applied_promotions']);
        $this->assertContains((float) $promoA['id'], $appliedIds);
        $this->assertContains((float) $promoB['id'], $appliedIds);
    }

    public function test_category_promotion_matches_only_category_members(): void
    {
        $categoryA = Category::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Cold Cuts',
            'sort_order' => 0,
        ]);
        $categoryB = Category::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Produce',
            'sort_order' => 1,
        ]);

        $productA = $this->makeProduct([
            'has_batch' => false,
            'category_id' => $categoryA->id,
            'price' => 10,
            'cost' => 3,
        ]);
        $productB = $this->makeProduct([
            'has_batch' => false,
            'category_id' => $categoryB->id,
            'price' => 10,
            'cost' => 3,
        ]);

        $this->postPromotion([
            'name' => 'Cold cuts 10%',
            'type' => 'category',
            'value' => 10,
            'category_id' => $categoryA->id,
        ]);

        $applied = $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 10],
                ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 10],
            ],
        ])->assertOk()->json();

        // Only product A qualifies; product B in another category gets no discount.
        $this->assertSame(1.0, (float) $applied['total_discount']);
        $this->assertSame(19.0, (float) $applied['cart_total']);
    }

    public function test_product_category_id_persists_and_descendant_filter(): void
    {
        $parent = Category::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Food',
            'sort_order' => 0,
        ]);

        $child = Category::create([
            'business_type_id' => $this->businessType->id,
            'parent_id' => $parent->id,
            'name' => 'Bakery',
            'sort_order' => 0,
        ]);

        $parentProduct = $this->postJson('/api/v1/products', [
            'name' => 'Parent product',
            'sku' => 'PAR-'.strtoupper(Str::random(6)),
            'price' => 5,
            'cost' => 2,
            'category_id' => $parent->id,
        ])->assertStatus(201)->json();

        $this->assertSame($parent->id, (int) $parentProduct['category_id']);
        $this->assertSame('Food', $parentProduct['category']);

        $childProduct = $this->postJson('/api/v1/products', [
            'name' => 'Child product',
            'sku' => 'CHI-'.strtoupper(Str::random(6)),
            'price' => 5,
            'cost' => 2,
            'category_id' => $child->id,
        ])->assertStatus(201)->json();

        $this->assertSame($child->id, (int) $childProduct['category_id']);
        $this->assertSame('Bakery', $childProduct['category']);

        // Filtering by the parent includes both the parent product and its descendant.
        $parentList = $this->getJson('/api/v1/products?category_id='.$parent->id)
            ->assertOk()->json()['data'];

        $parentIds = array_column($parentList, 'id');
        $this->assertContains($parentProduct['id'], $parentIds);
        $this->assertContains($childProduct['id'], $parentIds);

        $childList = $this->getJson('/api/v1/products?category_id='.$child->id)
            ->assertOk()->json()['data'];

        $childIds = array_column($childList, 'id');
        $this->assertContains($childProduct['id'], $childIds);
        $this->assertNotContains($parentProduct['id'], $childIds);
    }

    public function test_product_category_id_rejects_out_of_scope_category(): void
    {
        $otherType = BusinessType::create([
            'slug' => 'other-store-'.Str::random(4),
            'name_en' => 'Other Store',
            'name_ar' => 'متجر آخر',
            'allowed_modules' => ['sales'],
            'default_settings' => [],
        ]);

        $foreignCategory = Category::create([
            'business_type_id' => $otherType->id,
            'name' => 'Foreign',
            'sort_order' => 0,
        ]);

        $this->postJson('/api/v1/products', [
            'name' => 'Bad category',
            'sku' => 'BAD-'.strtoupper(Str::random(6)),
            'price' => 5,
            'cost' => 2,
            'category_id' => $foreignCategory->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_happy_hour_uses_client_local_time_when_server_clock_is_outside_window(): void
    {
        // Server is at 16:00 UTC; the store is several hours ahead so local time is 21:00.
        $this->travelTo(Carbon::parse('2026-08-15 16:00:00'));

        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Evening happy hour',
            'type' => 'happy_hour',
            'value' => 20,
            'happy_hour_start' => '18:00',
            'happy_hour_end' => '23:59',
            'applicable_products' => [$product->id],
        ]);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10],
            ],
        ];

        // Without the client clock the UTC server time (16:00) is outside the window.
        $noLocal = $this->postJson('/api/v1/promotions/apply', $payload)->assertOk()->json();
        $this->assertSame(0.0, (float) $noLocal['total_discount']);

        // With the client's local wall clock (21:00) the happy hour is active.
        $withLocal = $this->postJson('/api/v1/promotions/apply', array_merge($payload, ['local_time' => '21:00']))->assertOk()->json();
        $this->assertSame(4.0, (float) $withLocal['total_discount']);
        $this->assertSame('Evening happy hour', $withLocal['applied_promotions'][0]['name']);
        $this->assertSame(16.0, (float) $withLocal['cart_total']);
    }

    public function test_happy_hour_client_local_time_supports_overnight_window(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 16:00:00'));

        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postPromotion([
            'name' => 'Overnight evening deal',
            'type' => 'happy_hour',
            'value' => 10,
            'happy_hour_start' => '18:00',
            'happy_hour_end' => '00:00',
            'applicable_products' => [$product->id],
        ]);

        $items = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10],
            ],
        ];

        // 21:00 local is inside 18:00 -> 00:00.
        $active = $this->postJson('/api/v1/promotions/apply', array_merge($items, ['local_time' => '21:00']))->assertOk()->json();
        $this->assertSame(1.0, (float) $active['total_discount']);

        // 00:30 local is after the midnight end -> inactive.
        $after = $this->postJson('/api/v1/promotions/apply', array_merge($items, ['local_time' => '00:30']))->assertOk()->json();
        $this->assertSame(0.0, (float) $after['total_discount']);
        $this->assertSame([], $after['applied_promotions']);
    }

    public function test_happy_hour_apply_rejects_invalid_local_time(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'price' => 10, 'cost' => 4]);

        $this->postJson('/api/v1/promotions/apply', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10],
            ],
            'local_time' => '25:99',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('local_time');
    }

    private function makeSaleableProduct(float $price = 10, float $cost = 4, int $stock = 100): Product
    {
        return $this->makeProduct([
            'has_batch' => false,
            'price' => $price,
            'cost' => $cost,
            'stock_quantity' => $stock,
        ]);
    }

    public function test_checkout_records_promotion_usage(): void
    {
        $product = $this->makeSaleableProduct(10, 4);
        $promo = $this->postPromotion([
            'name' => '10% off',
            'type' => 'percentage',
            'value' => 10,
            'applicable_products' => [$product->id],
        ]);

        $invoice = $this->postJson('/api/v1/invoices', [
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 0, 'discount' => 0],
            ],
            'discount_amount' => 2,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'promotions' => [
                ['id' => (int) $promo['id'], 'discount' => 2],
            ],
        ])->assertStatus(201)->json();

        $usage = PromotionUsage::where('invoice_id', $invoice['id'])->first();
        $this->assertNotNull($usage);
        $this->assertSame((int) $promo['id'], (int) $usage->promotion_id);
        $this->assertSame(2.0, (float) $usage->discount_amount);
        $this->assertSame(20.0, (float) $usage->associated_revenue);
        $this->assertSame([['id' => (int) $promo['id'], 'discount' => 2]], $invoice['metadata']['promotions']);
        $this->assertSame(1, (int) Promotion::findOrFail((int) $promo['id'])->current_uses);
    }

    public function test_checkout_attribute_revenue_proportionally_across_promotions(): void
    {
        $productA = $this->makeSaleableProduct(10, 4, 100);
        $productB = $this->makeSaleableProduct(20, 5, 100);

        $promoA = $this->postPromotion([
            'name' => 'A deal',
            'type' => 'percentage',
            'value' => 10,
            'applicable_products' => [$productA->id],
        ]);

        $promoB = $this->postPromotion([
            'name' => 'B deal',
            'type' => 'percentage',
            'value' => 25,
            'applicable_products' => [$productB->id],
        ]);

        $invoice = $this->postJson('/api/v1/invoices', [
            'items' => [
                ['product_id' => $productA->id, 'name' => $productA->name, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0, 'discount' => 0],
                ['product_id' => $productB->id, 'name' => $productB->name, 'quantity' => 2, 'unit_price' => 20, 'tax_rate' => 0, 'discount' => 0],
            ],
            'discount_amount' => 11,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'promotions' => [
                ['id' => (int) $promoA['id'], 'discount' => 1],
                ['id' => (int) $promoB['id'], 'discount' => 10],
            ],
        ])->assertStatus(201)->json();

        $usages = PromotionUsage::where('invoice_id', $invoice['id'])->get();
        $this->assertCount(2, $usages);

        $byPromo = $usages->keyBy(fn ($u) => (int) $u->promotion_id);
        $this->assertSame(1.0, (float) $byPromo[(int) $promoA['id']]->discount_amount);
        $this->assertSame(10.0, (float) $byPromo[(int) $promoB['id']]->discount_amount);

        // 50 gross revenue split 1/11 vs 10/11 by discount share.
        $this->assertSame(4.55, (float) $byPromo[(int) $promoA['id']]->associated_revenue);
        $this->assertSame(45.45, (float) $byPromo[(int) $promoB['id']]->associated_revenue);

        $this->assertSame(1, (int) Promotion::findOrFail((int) $promoA['id'])->current_uses);
        $this->assertSame(1, (int) Promotion::findOrFail((int) $promoB['id'])->current_uses);
    }

    public function test_promotion_index_includes_stats_and_effectiveness(): void
    {
        $low = $this->postPromotion(['name' => 'Low impact', 'type' => 'percentage', 'value' => 10]);
        $high = $this->postPromotion(['name' => 'High impact', 'type' => 'fixed', 'value' => 5]);
        $margin = $this->postPromotion(['name' => 'Margin killer', 'type' => 'bogo', 'value' => 0]);

        PromotionUsage::create([
            'business_id' => $this->business->id,
            'promotion_id' => (int) $low['id'],
            'invoice_id' => $this->fakeInvoiceId(),
            'discount_amount' => 2,
            'associated_revenue' => 50,
        ]);

        for ($i = 0; $i < 25; $i++) {
            PromotionUsage::create([
                'business_id' => $this->business->id,
                'promotion_id' => (int) $high['id'],
                'invoice_id' => $this->fakeInvoiceId(),
                'discount_amount' => 2,
                'associated_revenue' => 50,
            ]);
        }

        PromotionUsage::create([
            'business_id' => $this->business->id,
            'promotion_id' => (int) $margin['id'],
            'invoice_id' => $this->fakeInvoiceId(),
            'discount_amount' => 40,
            'associated_revenue' => 100,
        ]);

        $rows = $this->getJson('/api/v1/promotions')->assertOk()->json()['data'];
        $byName = collect($rows)->keyBy('name');

        $lowStats = $byName['Low impact']['stats'];
        $this->assertSame(1, $lowStats['times_used']);
        $this->assertSame(50.0, (float) $lowStats['total_revenue']);
        $this->assertSame(2.0, (float) $lowStats['total_discount']);
        $this->assertSame('low_impact', $lowStats['effectiveness']);

        $highStats = $byName['High impact']['stats'];
        $this->assertSame(25, $highStats['times_used']);
        $this->assertSame(1250.0, (float) $highStats['total_revenue']);
        $this->assertSame('high_impact', $highStats['effectiveness']);

        $marginStats = $byName['Margin killer']['stats'];
        $this->assertSame(1, $marginStats['times_used']);
        $this->assertSame('negative_margin', $marginStats['effectiveness']);
    }

    private function fakeInvoiceId(): int
    {
        $invoice = \App\Models\Invoice::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoice_number' => 'ANL-'.strtoupper(Str::random(8)),
            'status' => 'paid',
            'payment_status' => 'paid',
            'total_amount' => 0,
            'net_amount' => 0,
        ]);

        return (int) $invoice->id;
    }

    public function test_checkout_rejects_promotion_from_another_business(): void
    {
        $otherType = BusinessType::create([
            'slug' => 'other-'.Str::random(4),
            'name_en' => 'Other',
            'name_ar' => 'آخر',
            'allowed_modules' => ['sales'],
            'default_settings' => [],
        ]);

        $other = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $otherType->id,
            'name' => 'Other Mart',
            'slug' => 'other-mart-'.Str::random(4),
            'status' => 'active',
        ]);

        $foreignPromo = \App\Models\Promotion::create([
            'business_id' => $other->id,
            'name' => 'Foreign deal',
            'type' => 'percentage',
            'value' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(7),
        ]);

        $product = $this->makeSaleableProduct();

        $this->postJson('/api/v1/invoices', [
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0, 'discount' => 0],
            ],
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'promotions' => [
                ['id' => (int) $foreignPromo->id, 'discount' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('promotions.0.id');
    }

    public function test_void_invoice_reverses_promotion_usage(): void
    {
        $product = $this->makeSaleableProduct(10, 4);
        $promo = $this->postPromotion([
            'name' => 'Voided deal',
            'type' => 'percentage',
            'value' => 10,
            'applicable_products' => [$product->id],
        ]);

        $invoice = $this->postJson('/api/v1/invoices', [
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 0, 'discount' => 0],
            ],
            'discount_amount' => 2,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'promotions' => [
                ['id' => (int) $promo['id'], 'discount' => 2],
            ],
        ])->assertStatus(201)->json();

        $this->assertSame(1, PromotionUsage::where('invoice_id', $invoice['id'])->count());
        $this->assertSame(1, (int) Promotion::findOrFail((int) $promo['id'])->current_uses);

        $this->postJson("/api/v1/invoices/{$invoice['id']}/void")->assertOk();

        $this->assertSame(0, PromotionUsage::where('invoice_id', $invoice['id'])->count());
        $this->assertSame(0, (int) Promotion::findOrFail((int) $promo['id'])->current_uses);
    }
}
