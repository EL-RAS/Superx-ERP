<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\InventoryAdjustment;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductQuantityTest extends TestCase
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
            'allowed_modules' => ['inventory', 'pos', 'purchases', 'sales'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Quantity Enforcement Retail',
            'slug' => 'quantity-enforcement-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'quantity-enforcement-user',
            'email' => 'quantity-enforcement@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeProduct(string $unit = 'pcs', bool $isWeighable = false, bool $hasBatch = false): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Qty Product '.$unit,
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 0,
            'unit' => $unit,
            'is_weighable' => $isWeighable,
            'has_batch' => $hasBatch,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Qty Supplier',
            'is_active' => true,
        ]);
    }

    private function makeBatch(Product $product, Supplier $supplier, float $quantity = 10): ProductBatch
    {
        return ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'BATCH-'.strtoupper(Str::random(6)),
            'source_type' => 'goods_receipt',
            'supplier_id' => $supplier->id,
            'quantity' => $quantity,
            'quantity_sold' => 0,
            'expiry_date' => now()->addYear()->toDateString(),
            'cost_per_unit' => 4,
            'is_active' => true,
        ]);
    }

    private function grnPayload(Product $product, float $quantity): array
    {
        return [
            'supplier_id' => $product->business_id === $this->business->id ? $this->makeSupplier()->id : null,
            'items' => [
                [
                    'product_id' => $product->id,
                    'received_quantity' => $quantity,
                    'unit_cost' => 4,
                    'expiry_date' => now()->addYear()->toDateString(),
                ],
            ],
        ];
    }

    public function test_grn_rejects_fractional_quantity_for_piece_product(): void
    {
        $product = $this->makeProduct('pcs');

        $response = $this->postJson('/api/v1/goods-receipts', $this->grnPayload($product, 10.5));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.received_quantity');
        $this->assertStringContainsString('whole number', $response->json('errors')['items.0.received_quantity'][0]);
        $this->assertSame(0, ProductBatch::where('business_id', $this->business->id)->count());
    }

    public function test_grn_rejects_fractional_quantity_for_piece_product_with_empty_unit(): void
    {
        $product = $this->makeProduct('');

        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($product, 3.75))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.received_quantity');
    }

    public function test_grn_accepts_whole_quantity_for_piece_product(): void
    {
        $product = $this->makeProduct('pcs');

        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($product, 10))
            ->assertStatus(201);
    }

    public function test_grn_accepts_decimal_quantity_for_weighable_and_measured_products(): void
    {
        $weighable = $this->makeProduct('pcs', isWeighable: true);
        $measured = $this->makeProduct('kg');

        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($weighable, 10.5))->assertStatus(201);
        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($weighable, 10))->assertStatus(201);
        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($measured, 1.275))->assertStatus(201);
        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($measured, 0.5))->assertStatus(201);
    }

    public function test_grn_rejects_decimal_beyond_three_places_for_measured_products(): void
    {
        $product = $this->makeProduct('kg');

        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($product, 1.2754))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.received_quantity');

        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($product, 1.275))
            ->assertStatus(201);

        $this->postJson('/api/v1/goods-receipts', $this->grnPayload($product, 1.270))
            ->assertStatus(201);
    }

    public function test_po_rejects_fractional_quantity_for_piece_product(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct('pcs');

        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 8.5, 'unit_cost' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.quantity');
        $this->assertSame(0, PurchaseOrder::where('business_id', $this->business->id)->count());
    }

    public function test_po_accepts_fractional_quantity_for_weighable_product(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct('kg', isWeighable: true);

        $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 2.75, 'unit_cost' => 2],
            ],
        ])->assertStatus(201);
    }

    public function test_inventory_adjustment_rejects_fractional_quantity_for_piece_product(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct('pcs');
        $batch = $this->makeBatch($product, $supplier, 10);

        $response = $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'type' => 'waste',
            'quantity' => 2.5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('quantity');
        $this->assertSame(0, InventoryAdjustment::where('business_id', $this->business->id)->count());
    }

    public function test_inventory_adjustment_accepts_decimal_quantity_for_weighable_product(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct('kg', isWeighable: true);
        $batch = $this->makeBatch($product, $supplier, 5.5);

        $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'type' => 'waste',
            'quantity' => 0.75,
        ])->assertStatus(201);
    }

    public function test_invoice_rejects_fractional_quantity_for_piece_product(): void
    {
        $product = $this->makeProduct('pcs');

        $response = $this->postJson('/api/v1/invoices', [
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 3.14, 'unit_price' => 10],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_invoice_accepts_decimal_quantity_for_weighable_product(): void
    {
        $product = $this->makeProduct('kg', isWeighable: true);

        $this->postJson('/api/v1/invoices', [
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 0.75, 'unit_price' => 10],
            ],
        ])->assertStatus(201);
    }

    public function test_imported_item_without_product_id_skips_piece_check(): void
    {
        $supplier = $this->makeSupplier();

        $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['name' => 'New Item', 'quantity' => 5, 'unit_cost' => 2, 'import_product' => true],
            ],
        ])->assertStatus(201);
    }

    public function test_return_exchange_rejects_fractional_exchange_quantity_for_piece_product(): void
    {
        $product = $this->makeProduct('pcs');
        $invoice = $this->postJson('/api/v1/invoices', [
            'customer_id' => null,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 4, 'unit_price' => 10],
            ],
        ])->assertStatus(201)->json();

        $invoiceItemId = InvoiceItem::where('invoice_id', $invoice['id'])->firstOrFail()->id;

        $response = $this->postJson('/api/v1/returns-exchanges', [
            'invoice_id' => $invoice['id'],
            'type' => 'exchange',
            'items' => [
                ['invoice_item_id' => $invoiceItemId, 'quantity' => 1, 'is_exchange' => false],
            ],
            'exchange_items' => [
                ['product_id' => $product->id, 'quantity' => 2.5, 'unit_price' => 10],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('exchange_items.0.quantity');
    }

    public function test_fractional_piece_stock_is_rounded_by_cleanup_migration(): void
    {
        $piece = $this->makeProduct('pcs');
        $piece->forceFill(['stock_quantity' => 10.49])->save();

        $measured = $this->makeProduct('kg', isWeighable: true);
        $measured->forceFill(['stock_quantity' => 1.275])->save();

        $supplier = $this->makeSupplier();
        $pieceBatch = $this->makeBatch($piece, $supplier, 20.37);
        $measuredBatch = $this->makeBatch($measured, $supplier, 3.141);

        $migration = require database_path('migrations/2026_09_16_000001_round_fractional_piece_quantities.php');
        $migration->up();

        $this->assertSame(10.0, (float) $piece->fresh()->stock_quantity);
        $this->assertSame(20.0, (float) $pieceBatch->fresh()->quantity);
        $this->assertSame(1.28, (float) $measured->fresh()->stock_quantity);
        $this->assertSame(3.14, (float) $measuredBatch->fresh()->quantity);
    }
}
