<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Scopes\BusinessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GrnProvisionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'purchases'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'GRN Provision Retail',
            'slug' => 'grn-provision-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'grn-provision-user',
            'email' => 'grn-provision@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);

        $this->supplier = Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'GRN Provision Supplier',
            'is_active' => true,
        ]);
    }

    private function unlinkedCatalogRow(string $name, float $cost): SupplierProduct
    {
        return SupplierProduct::create([
            'business_id' => $this->business->id,
            'supplier_id' => $this->supplier->id,
            'name' => $name,
            'catalog_cost' => $cost,
            'is_imported' => false,
        ]);
    }

    private function productCount(): int
    {
        return Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count();
    }

    public function test_direct_grn_provisions_missing_product_and_links_catalog_row(): void
    {
        $pivot = $this->unlinkedCatalogRow('Fresh Apples', 1.5);
        $before = $this->productCount();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'credit',
            'items' => [
                [
                    'name' => 'Fresh Apples',
                    'supplier_product_id' => $pivot->id,
                    'received_quantity' => 12,
                    'unit_cost' => 1.5,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->productCount());

        $product = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('name', 'Fresh Apples')
            ->firstOrFail();

        $this->assertSame('pcs', $product->unit);
        $this->assertSame(1.5, (float) $product->cost);
        $this->assertSame(4.5, (float) $product->price);
        $this->assertSame(12.0, (float) $product->stock_quantity);
        $this->assertTrue((bool) $product->is_active);
        $this->assertNull($product->barcode);
        $this->assertStringContainsString('-GEN-', (string) $product->sku);
        $this->assertSame('grn_import', data_get($product->metadata, 'source'));

        $pivot->refresh();
        $this->assertSame($product->id, $pivot->product_id);
        $this->assertTrue((bool) $pivot->is_imported);

        $receipt = GoodsReceipt::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->firstOrFail();
        $this->assertSame(18.0, (float) $receipt->total_amount);

        $line = GoodsReceiptItem::withoutGlobalScope(BusinessScope::class)
            ->where('goods_receipt_id', $receipt->id)
            ->firstOrFail();
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('Fresh Apples', $line->name);

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();
        $this->assertSame(round((float) $lines->sum('debit'), 2), round((float) $lines->sum('credit'), 2));
        $this->assertSame(18.0, round((float) $lines->sum('debit'), 2));
    }

    public function test_direct_grn_new_item_with_expiry_becomes_a_batch_product(): void
    {
        $before = $this->productCount();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                [
                    'name' => 'Chilled Yoghurt',
                    'received_quantity' => 6,
                    'unit_cost' => 0.8,
                    'expiry_date' => now()->addDays(14)->toDateString(),
                    'storage_location' => 'Fridge 2',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame($before + 1, $this->productCount());

        $product = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('name', 'Chilled Yoghurt')
            ->firstOrFail();

        $this->assertTrue((bool) $product->has_batch);
        $this->assertTrue((bool) $product->has_expiry);
        // Batch-managed stock is mirrored from its batches by refreshMetrics().
        $this->assertSame(6.0, (float) $product->stock_quantity);

        $batch = ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('goods_receipt', $batch->source_type);
        $this->assertSame(6.0, (float) $batch->quantity);
        $this->assertSame('Fridge 2', $batch->storage_location);
        $this->assertSame($this->supplier->id, $batch->supplier_id);
    }

    public function test_direct_grn_new_item_without_expiry_stays_a_simple_product(): void
    {
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['name' => 'Paper Towels', 'received_quantity' => 4, 'unit_cost' => 2],
            ],
        ])->assertStatus(201);

        $product = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('name', 'Paper Towels')
            ->firstOrFail();

        $this->assertFalse((bool) $product->has_batch);
        $this->assertFalse((bool) $product->has_expiry);
        $this->assertSame(4.0, (float) $product->stock_quantity);
        $this->assertSame(0, ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('product_id', $product->id)->count());
    }

    public function test_direct_grn_fractional_new_item_becomes_weighed_so_it_can_be_received_again(): void
    {
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['name' => 'Saffron Threads', 'received_quantity' => 1.25, 'unit_cost' => 40],
            ],
        ])->assertStatus(201);

        $product = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('name', 'Saffron Threads')
            ->firstOrFail();

        $this->assertTrue((bool) $product->is_weighable);
        $this->assertSame('kg', $product->unit);

        // The follow-up fractional receipt must still validate now that the
        // product exists — a piece product would reject it as non-whole.
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 0.5, 'unit_cost' => 40],
            ],
        ])->assertStatus(201);

        $this->assertSame(1.75, (float) $product->fresh()->stock_quantity);
    }

    public function test_direct_grn_new_item_requires_a_name(): void
    {
        $before = $this->productCount();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'items' => [['received_quantity' => 3, 'unit_cost' => 2]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.name');
        $this->assertSame($before, $this->productCount());
        $this->assertSame(0, GoodsReceipt::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }

    public function test_direct_grn_new_item_requires_a_unit_cost(): void
    {
        $before = $this->productCount();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'items' => [['name' => 'Mystery Item', 'received_quantity' => 3]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.unit_cost');
        $this->assertSame($before, $this->productCount());
    }

    public function test_po_grn_still_requires_a_product_id(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Ordered Product',
            'sku' => 'ORD-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 2,
            'tax_rate' => 0,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-'.strtoupper(Str::random(6)),
            'status' => 'ordered',
            'total_amount' => 20.0,
        ]);

        $poItem = PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 10,
            'unit_cost' => 2,
            'total' => 20.0,
        ]);

        $before = $this->productCount();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'received_quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.product_id');
        $this->assertSame($before, $this->productCount());
        $this->assertSame('0.00', $poItem->fresh()->received_quantity);
    }

    public function test_supplier_catalog_add_requires_a_product_or_a_name(): void
    {
        $this->postJson("/api/v1/suppliers/{$this->supplier->id}/products", [
            'catalog_cost' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson("/api/v1/suppliers/{$this->supplier->id}/products", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_supplier_catalog_add_links_an_existing_product_from_product_id_only(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Linked Product',
            'sku' => 'LNK-'.strtoupper(Str::random(6)),
            'price' => 8,
            'cost' => 3,
            'tax_rate' => 0,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $response = $this->postJson("/api/v1/suppliers/{$this->supplier->id}/products", [
            'product_id' => $product->id,
            'catalog_cost' => 3.25,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Linked Product', $response->json('name'));
        $this->assertSame($product->id, $response->json('product_id'));
        $this->assertTrue((bool) $response->json('is_imported'));
        $this->assertSame(3.25, (float) $response->json('catalog_cost'));
    }

    public function test_supplier_catalog_add_stores_a_free_text_item(): void
    {
        $response = $this->postJson("/api/v1/suppliers/{$this->supplier->id}/products", [
            'name' => 'Seasonal Crate',
            'catalog_cost' => 12,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Seasonal Crate', $response->json('name'));
        $this->assertNull($response->json('product_id'));
        $this->assertFalse((bool) $response->json('is_imported'));
    }

    public function test_supplier_catalog_add_rejects_a_foreign_product(): void
    {
        $otherType = BusinessType::create([
            'slug' => 'fashion',
            'name_en' => 'Fashion',
            'name_ar' => 'أزياء',
            'allowed_modules' => ['inventory', 'purchases'],
        ]);

        $otherBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $otherType->id,
            'name' => 'Other Retailer',
            'slug' => 'other-retailer',
            'status' => 'active',
        ]);

        $foreign = Product::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Foreign Product',
            'sku' => 'FOR-0001',
            'price' => 5,
            'cost' => 1,
            'tax_rate' => 0,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $this->postJson("/api/v1/suppliers/{$this->supplier->id}/products", [
            'product_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }
}
