<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Product pickers (Add Batch, invoices, GRN, ...) consume GET /products, which
 * paginates at a backend default of 10 rows. That default is what truncated the
 * Add Batch selector to 10 of 355 products, so these tests pin the two query
 * parameters the searchable combobox depends on: per_page (escape the ceiling)
 * and search (name / SKU / barcode).
 */
class ProductSearchTest extends TestCase
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
            'allowed_modules' => ['inventory', 'pos', 'sales'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Product Search Retail',
            'slug' => 'product-search-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Picker User',
            'username' => 'product-search-user',
            'email' => 'product-search@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeProduct(string $name, string $sku, string $barcode): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
            'price' => 5,
            'cost' => 2,
            'tax_rate' => 0,
            'unit' => 'pcs',
            'is_active' => true,
            'stock_quantity' => 0,
        ]);
    }

    /** A 13-digit barcode whose last two digits are $i. */
    private function barcode(string $prefix, int $i): string
    {
        return $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }

    public function test_products_index_defaults_to_ten_rows(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeProduct("Listed Item {$i}", "LIST-{$i}", $this->barcode('11111111111', $i));
        }

        $page = $this->getJson('/api/v1/products')->assertOk()->json();

        // The default is exactly what truncated the Add Batch selector.
        $this->assertSame(10, $page['per_page']);
        $this->assertCount(10, $page['data']);
        $this->assertSame(25, $page['total']);
    }

    public function test_per_page_escapes_the_default_ten_row_ceiling(): void
    {
        foreach (range(1, 35) as $i) {
            $this->makeProduct("Catalog Item {$i}", "CAT-{$i}", $this->barcode('22222222222', $i));
        }

        $page = $this->getJson('/api/v1/products?per_page=50')->assertOk()->json();

        $this->assertCount(35, $page['data']);
        $this->assertSame(35, $page['total']);
        $this->assertSame(50, $page['per_page']);
    }

    public function test_search_filters_by_name_sku_and_barcode(): void
    {
        $this->makeProduct('Jordan Sneakers', 'SHOE-JORDAN', '3333333333301');
        $this->makeProduct('Leather Belt', 'BELT-001', '4444444444401');
        $this->makeProduct('Cotton Socks', 'SOCK-001', '5555555555501');

        $byName = $this->getJson('/api/v1/products?search=sneaker')->assertOk()->json();
        $this->assertCount(1, $byName['data']);
        $this->assertSame('Jordan Sneakers', $byName['data'][0]['name']);

        $bySku = $this->getJson('/api/v1/products?search=SHOE-JORD')->assertOk()->json();
        $this->assertCount(1, $bySku['data']);
        $this->assertSame('Jordan Sneakers', $bySku['data'][0]['name']);

        $byBarcode = $this->getJson('/api/v1/products?search=5555555555501')->assertOk()->json();
        $this->assertCount(1, $byBarcode['data']);
        $this->assertSame('Cotton Socks', $byBarcode['data'][0]['name']);

        $noMatch = $this->getJson('/api/v1/products?search=does-not-exist')->assertOk()->json();
        $this->assertCount(0, $noMatch['data']);
    }

    public function test_search_combines_with_per_page_for_the_combobox_query(): void
    {
        foreach (range(1, 30) as $i) {
            $this->makeProduct("Selector Widget {$i}", "WID-{$i}", $this->barcode('66666666666', $i));
        }
        $this->makeProduct('Unrelated Kettle', 'KETTLE-1', '7777777777701');

        $page = $this->getJson('/api/v1/products?search=Selector&per_page=20')->assertOk()->json();

        $this->assertCount(20, $page['data']);
        $this->assertSame(30, $page['total']);
        $this->assertTrue(
            collect($page['data'])->every(fn (array $row) => str_contains($row['name'], 'Selector Widget'))
        );
    }

    public function test_search_is_scoped_to_the_requesting_business(): void
    {
        $this->makeProduct('Local Coffee Beans', 'LOCAL-BEANS', '8888888888801');

        $foreignType = BusinessType::create([
            'slug' => 'retail',
            'name_en' => 'Retail',
            'name_ar' => 'تجزئة',
            'allowed_modules' => ['inventory'],
        ]);
        $foreign = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $foreignType->id,
            'name' => 'Foreign Retail',
            'slug' => 'foreign-retail',
            'status' => 'active',
        ]);
        $foreignUser = User::create([
            'business_id' => $foreign->id,
            'name' => 'Foreign User',
            'username' => 'foreign-product-search-user',
            'email' => 'foreign-product-search@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
        Product::create([
            'business_id' => $foreign->id,
            'created_by' => $foreignUser->id,
            'name' => 'Foreign Coffee Beans',
            'sku' => 'FOREIGN-BEANS',
            'barcode' => '9999999999901',
            'price' => 5,
            'cost' => 2,
            'tax_rate' => 0,
            'unit' => 'pcs',
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        // The foreign product shares the "Coffee Beans" phrase; only the local row may surface.
        $page = $this->getJson('/api/v1/products?search=Coffee Beans')->assertOk()->json();

        $this->assertCount(1, $page['data']);
        $this->assertSame('Local Coffee Beans', $page['data'][0]['name']);
    }
}
