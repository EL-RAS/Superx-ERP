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
}
