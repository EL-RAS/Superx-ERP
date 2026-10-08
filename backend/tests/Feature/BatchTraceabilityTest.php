<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchTraceabilityTest extends TestCase
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
            'allowed_modules' => ['inventory', 'pos'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Batch Traceability Retail',
            'slug' => 'batch-traceability-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'batch-traceability-user',
            'email' => 'batch-traceability@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeProduct(bool $hasBatch = true): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Batch Traced Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 0,
            'has_batch' => $hasBatch,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Trace Supplier',
            'is_active' => true,
        ]);
    }

    private function makeBatch(Product $product, Supplier $supplier, float $quantity = 10, float $costPerUnit = 4.0): ProductBatch
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
            'cost_per_unit' => $costPerUnit,
            'is_active' => true,
        ]);
    }

    private function makeOrderedPo(Supplier $supplier, Product $product): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-'.strtoupper(Str::random(6)),
            'status' => 'ordered',
            'total_amount' => 8,
        ]);

        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => 'Traced Item',
            'quantity' => 1,
            'unit_cost' => 8,
            'total' => 8,
        ]);

        return $po;
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function entriesOfType(string $referenceType): Collection
    {
        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', $referenceType)
            ->orderBy('id')
            ->get();
    }

    private function lineBalanceOf(int $entryId, int $accountId): float
    {
        $query = JournalEntryLine::withoutGlobalScope(BusinessScope::class)->where('journal_entry_id', $entryId);

        return round(
            (float) (clone $query)->where('account_id', $accountId)->sum('debit')
            - (float) (clone $query)->where('account_id', $accountId)->sum('credit'),
            2
        );
    }

    private function accountId(string $code): ?int
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

    public function test_manual_batch_store_accepts_source_type_and_supplier(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'OPEN-001',
            'source_type' => 'opening_stock',
            'supplier_id' => $supplier->id,
            'quantity' => 25,
            'total_cost' => 75,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertSame('opening_stock', $response->json('source_type'));
        $this->assertSame($supplier->id, $response->json('supplier_id'));

        $index = $this->getJson('/api/v1/product-batches?per_page=50')->assertOk()->json();
        $row = collect($index['data'])->firstWhere('id', $response->json('id'));
        $this->assertNotNull($row);
        $this->assertSame('Trace Supplier', $row['supplier']['name']);
        $this->assertSame('opening_stock', $row['source_type']);
    }

    public function test_grn_receipt_stamps_batch_source_and_receipt_reference(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($supplier, $product);
        $poItem = $po->items()->firstOrFail();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_item_id' => $poItem->id,
                    'received_quantity' => 1,
                    'unit_cost' => 8,
                    'expiry_date' => now()->addYear()->toDateString(),
                ],
            ],
        ]);

        $response->assertStatus(201);
        $receiptId = $response->json('id');

        $batch = ProductBatch::where('goods_receipt_id', $receiptId)->firstOrFail();
        $this->assertSame('goods_receipt', $batch->source_type);
        $this->assertSame((int) $receiptId, (int) $batch->goods_receipt_id);
        $this->assertSame($supplier->id, $batch->supplier_id);

        $index = $this->getJson('/api/v1/product-batches?per_page=50')->assertOk()->json();
        $row = collect($index['data'])->firstWhere('id', $batch->id);
        $this->assertNotNull($row);
        $this->assertSame('goods_receipt', $row['source_type']);
        $this->assertSame('GRN-1', $row['goods_receipt']['receipt_number']);
        $this->assertSame('Trace Supplier', $row['supplier']['name']);
    }

    public function test_damage_supplier_claim_posts_debit_note_and_ledger_row(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $this->makeBatch($product, $supplier, 10, 4.0);

        $response = $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => ProductBatch::where('product_id', $product->id)->first()->id,
            'type' => 'damage',
            'quantity' => 3,
            'unit_cost' => 4,
            'responsibility' => 'supplier',
            'supplier_id' => $supplier->id,
            'notes' => 'Broken during delivery',
        ]);

        $response->assertStatus(201);

        $this->assertSame('supplier_claim', $response->json('liability_type'));
        $this->assertSame($supplier->id, $response->json('supplier_id'));

        $entries = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame(12.0, $this->lineBalanceOf($entry->id, $this->accountId('2010')));
        $this->assertSame(-12.0, $this->lineBalanceOf($entry->id, $this->accountId('1030')));
        $this->assertSame('supplier', $entry->metadata['responsibility']);

        $ledger = $this->getJson('/api/v1/suppliers/'.$supplier->id.'/ledger')->assertOk()->json();
        $claimRow = collect($ledger['rows'])->firstWhere('kind', 'supplier_claim');
        $this->assertNotNull($claimRow);
        $this->assertSame(12.0, (float) $claimRow['debit']);
        $this->assertSame(-12.0, (float) $ledger['balance']);

        $show = $this->getJson('/api/v1/suppliers/'.$supplier->id)->assertOk()->json();
        $this->assertSame(-12.0, (float) $show['balance']);
    }

    public function test_waste_store_responsibility_posts_expense_account(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $this->makeBatch($product, $supplier, 10, 4.0);

        $response = $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => ProductBatch::where('product_id', $product->id)->first()->id,
            'type' => 'waste',
            'quantity' => 2,
            'notes' => 'Spoiled in storage',
        ]);

        $response->assertStatus(201);
        $this->assertSame('store', $response->json('metadata')['responsibility']);
        $this->assertSame('internal_store_loss', $response->json('liability_type'));

        $entry = $this->entriesOfType('inventory_adjustment')->first();
        $this->assertSame(8.0, $this->lineBalanceOf($entry->id, $this->accountId('5020')));
        $this->assertSame(-8.0, $this->lineBalanceOf($entry->id, $this->accountId('1030')));
        $this->assertSame(0.0, $this->lineBalanceOf($entry->id, $this->accountId('2010')));
    }

    public function test_supplier_claim_requires_a_supplier(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, $this->makeSupplier(), 10, 4.0);

        $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => ProductBatch::where('product_id', $product->id)->first()->id,
            'type' => 'damage',
            'quantity' => 1,
            'responsibility' => 'supplier',
        ])->assertStatus(422);

        $this->assertSame(0, InventoryAdjustment::where('business_id', $this->business->id)->count());
    }

    public function test_manual_batch_source_type_enum_and_default(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $finding = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'FIND-001',
            'source_type' => 'stock_count_finding',
            'supplier_id' => $supplier->id,
            'quantity' => 5,
            'total_cost' => 20,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $finding->assertStatus(201);
        $this->assertSame('stock_count_finding', $finding->json('source_type'));

        $defaulted = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'MANUAL-001',
            'quantity' => 3,
            'total_cost' => 12,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $defaulted->assertStatus(201);
        $this->assertSame('manual_entry', $defaulted->json('source_type'));

        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'LEGACY-001',
            'source_type' => 'manual_adjustment',
            'quantity' => 1,
            'total_cost' => 4,
        ])->assertStatus(422);
    }

    public function test_supplier_claim_generates_companion_purchase_return_debit_note(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $batch = $this->makeBatch($product, $supplier, 10, 4.0);

        $response = $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'type' => 'damage',
            'quantity' => 3,
            'unit_cost' => 4,
            'responsibility' => 'supplier',
            'supplier_id' => $supplier->id,
            'generate_purchase_return' => true,
            'notes' => 'Damaged goods',
        ]);

        $response->assertStatus(201);
        $claim = InventoryAdjustment::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('id', $response->json('id'))
            ->firstOrFail();

        $this->assertSame('supplier_claim', $claim->liability_type);
        $this->assertSame($supplier->id, (int) $claim->supplier_id);
        $this->assertNotNull($claim->purchase_return_id);

        $companion = InventoryAdjustment::withoutGlobalScope(BusinessScope::class)->find($claim->purchase_return_id);
        $this->assertNotNull($companion);
        $this->assertSame('purchase_return', $companion->type);
        $this->assertSame('supplier_claim', $companion->liability_type);
        $this->assertSame((float) -3, (float) $companion->quantity_adjusted);
        $this->assertSame(4.0, (float) $companion->unit_cost);
        $this->assertSame((int) $claim->id, (int) $companion->metadata['source_claim_id']);
        $this->assertTrue($companion->metadata['auto_generated']);
        $this->assertTrue($companion->metadata['debit_note']);

        $entries = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(1, $entries);
        $this->assertSame(12.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));

        $ledger = $this->getJson('/api/v1/suppliers/'.$supplier->id.'/ledger')->assertOk()->json();
        $this->assertCount(1, collect($ledger['rows'])->where('kind', 'supplier_claim'));
        $this->assertCount(0, collect($ledger['rows'])->where('kind', 'purchase_return'));
        $this->assertSame(-12.0, (float) $ledger['balance']);

        $show = $this->getJson('/api/v1/suppliers/'.$supplier->id)->assertOk()->json();
        $this->assertSame(-12.0, (float) $show['balance']);
    }

    public function test_batch_store_rejects_zero_and_fractional_piece_quantity(): void
    {
        $product = $this->makeProduct();
        $expiry = now()->addYear()->toDateString();

        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'ZERO-001',
            'quantity' => 0,
            'total_cost' => 0,
            'expiry_date' => $expiry,
        ])->assertStatus(422);

        $piece = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Piece Batch Product',
            'sku' => 'SKU-PIECE-BATCH',
            'price' => 10,
            'cost' => 4,
            'unit' => 'pcs',
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $fractional = $this->postJson('/api/v1/product-batches', [
            'product_id' => $piece->id,
            'batch_number' => 'FRAC-001',
            'quantity' => 2.5,
            'total_cost' => 10,
            'expiry_date' => $expiry,
        ]);
        $fractional->assertStatus(422);
        $fractional->assertJsonValidationErrors('quantity');

        $this->assertSame(0, ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count());
    }

    public function test_batch_store_rejects_expiry_before_manufacturing_date(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'DATES-001',
            'quantity' => 5,
            'total_cost' => 20,
            'manufacturing_date' => now()->addMonth()->toDateString(),
            'expiry_date' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('expiry_date');

        $this->assertSame(0, ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count());
    }

    public function test_batch_store_rejects_foreign_product_and_duplicate_number(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $expiry = now()->addYear()->toDateString();

        $foreignBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->business->business_type_id,
            'name' => 'Foreign Retail',
            'slug' => 'foreign-retail',
            'status' => 'active',
        ]);
        $foreignProduct = Product::create([
            'business_id' => $foreignBusiness->id,
            'created_by' => $this->user->id,
            'name' => 'Foreign Product',
            'sku' => 'SKU-FOREIGN',
            'price' => 10,
            'cost' => 4,
            'has_batch' => true,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $foreign = $this->postJson('/api/v1/product-batches', [
            'product_id' => $foreignProduct->id,
            'batch_number' => 'FOREIGN-001',
            'quantity' => 5,
            'total_cost' => 20,
            'expiry_date' => $expiry,
        ]);
        $foreign->assertStatus(422);
        $foreign->assertJsonValidationErrors('product_id');

        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'DUP-001',
            'supplier_id' => $supplier->id,
            'quantity' => 5,
            'total_cost' => 20,
            'expiry_date' => $expiry,
        ])->assertStatus(201);

        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'DUP-001',
            'supplier_id' => $supplier->id,
            'quantity' => 3,
            'total_cost' => 12,
            'expiry_date' => $expiry,
        ])->assertStatus(422);
    }

    public function test_batch_update_can_clear_expiry_and_rejects_foreign_product(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, $this->makeSupplier(), 5);

        $cleared = $this->patchJson('/api/v1/product-batches/'.$batch->id, [
            'expiry_date' => null,
            'batch_number' => '  RENAMED-001  ',
        ]);
        $cleared->assertStatus(200);
        $this->assertNull($batch->fresh()->expiry_date);
        $this->assertSame('RENAMED-001', $batch->fresh()->batch_number);

        $foreignBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->business->business_type_id,
            'name' => 'Foreign Retail Two',
            'slug' => 'foreign-retail-two',
            'status' => 'active',
        ]);
        $foreignProduct = Product::create([
            'business_id' => $foreignBusiness->id,
            'created_by' => $this->user->id,
            'name' => 'Foreign Product Two',
            'sku' => 'SKU-FOREIGN-2',
            'price' => 10,
            'cost' => 4,
            'has_batch' => true,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $this->patchJson('/api/v1/product-batches/'.$batch->id, [
            'product_id' => $foreignProduct->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_batch_destroy_remaining_stock_uses_exact_message(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, $this->makeSupplier(), 5);

        $response = $this->deleteJson('/api/v1/product-batches/'.$batch->id);
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cannot delete a batch with remaining stock. Please adjust the stock to zero before deleting.');
        $this->assertNotNull($batch->fresh());
    }

    public function test_sale_and_void_stamp_batch_on_stock_movements(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, $this->makeSupplier(), 10);

        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Traced Sale',
                    'quantity' => 3,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ],
            ],
        ]);
        $sale->assertStatus(201);
        $invoiceId = $sale->json('id');

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'reference_type' => 'invoice',
            'reference_id' => $invoiceId,
            'type' => 'reduction',
        ]);

        $this->postJson('/api/v1/invoices/'.$invoiceId.'/void')->assertStatus(200);

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'reference_type' => 'invoice_void',
            'reference_id' => $invoiceId,
            'type' => 'addition',
        ]);
    }

    public function test_manual_batch_on_simple_product_keeps_product_stock_intact(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Simple Stock Product',
            'sku' => 'SKU-SIMPLE-STOCK',
            'price' => 10,
            'cost' => 4,
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 7,
        ]);

        $batch = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'SIMPLE-001',
            'quantity' => 4,
            'total_cost' => 16,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $batch->assertStatus(201);
        $this->assertSame('7.00', $product->fresh()->stock_quantity);

        $this->patchJson('/api/v1/product-batches/'.$batch->json('id'), ['quantity' => 0])->assertStatus(200);
        $this->deleteJson('/api/v1/product-batches/'.$batch->json('id'))->assertStatus(200);
        $this->assertSame('7.00', $product->fresh()->stock_quantity);
    }

    public function test_batch_index_summary_breaks_down_inventory_value(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        // Healthy stock (beyond the warning window): 10 x 2.00 = 20 (active only).
        $this->makeBatch($product, $supplier, 10, 2.0)->forceFill(['expiry_date' => now()->addDays(90)->toDateString()])->save();

        // Expiring soon: 5 x 4.00 = 20 (expiring + active).
        $this->makeBatch($product, $supplier, 5, 4.0)->forceFill(['expiry_date' => now()->addDays(10)->toDateString()])->save();

        // Expired: 8 x 1.50 = 12 (expired only).
        $this->makeBatch($product, $supplier, 8, 1.5)->forceFill(['expiry_date' => now()->subDays(5)->toDateString()])->save();

        // No expiry: 3 x 10.00 = 30 (active only, null expiry is healthy stock).
        $this->makeBatch($product, $supplier, 3, 10.0)->forceFill(['expiry_date' => null])->save();

        // Partly sold, expiring soon: remaining 4 x 2.00 = 8 (expiring + active).
        $this->makeBatch($product, $supplier, 10, 2.0)->forceFill([
            'quantity_sold' => 6,
            'expiry_date' => now()->addDays(20)->toDateString(),
        ])->save();

        // Fully sold, expired: no remaining stock, must NOT be valued.
        $this->makeBatch($product, $supplier, 4, 5.0)->forceFill([
            'quantity_sold' => 4,
            'expiry_date' => now()->subDays(2)->toDateString(),
        ])->save();

        // Expired, sold 2 + returned 1: remaining 7 x 3.00 = 21 (expired only).
        $this->makeBatch($product, $supplier, 10, 3.0)->forceFill([
            'quantity_sold' => 2,
            'quantity_returned' => 1,
            'expiry_date' => now()->subDays(3)->toDateString(),
        ])->save();

        $this->getJson('/api/v1/product-batches')
            ->assertOk()
            ->assertJsonPath('summary.active_value', 78)
            ->assertJsonPath('summary.expiring_soon_value', 28)
            ->assertJsonPath('summary.expired_value', 33);
    }
}
