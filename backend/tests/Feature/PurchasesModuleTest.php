<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\JournalEntry;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchasesModuleTest extends TestCase
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
            'name' => 'Purchases Test Retail',
            'slug' => 'purchases-test-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'purchases-test-user',
            'email' => 'purchases-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeProduct(bool $hasBatch = false, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Purchased Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 2,
            'tax_rate' => 0,
            'has_batch' => $hasBatch,
            'is_active' => true,
            'stock_quantity' => 0,
        ], $overrides));
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Purchases Test Supplier',
            'is_active' => true,
        ]);
    }

    private function makeOrderedPo(Product $product, Supplier $supplier, float $quantity = 10, float $unitCost = 2): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-'.strtoupper(Str::random(6)),
            'status' => 'ordered',
            'total_amount' => round($quantity * $unitCost, 2),
        ]);

        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total' => round($quantity * $unitCost, 2),
        ]);

        return $po;
    }

    private function accountId(string $code): ?int
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

    /**
     * @return array{entries: Collection<int, JournalEntry>, lines: Collection<int, JournalEntryLine>, debit: float, credit: float}
     */
    private function journalState(): array
    {
        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();

        return [
            'entries' => JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->orderBy('id')
                ->get(),
            'lines' => $lines,
            'debit' => round((float) $lines->sum('debit'), 2),
            'credit' => round((float) $lines->sum('credit'), 2),
        ];
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
        return round(
            (float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $accountId)
                ->sum('debit')
            - (float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $accountId)
                ->sum('credit'),
            2
        );
    }

    public function test_supplier_catalog_relations_partition_available_vs_imported(): void
    {
        $product = $this->makeProduct(hasBatch: false);
        $supplier = $this->makeSupplier();

        $this->postJson('/api/v1/suppliers/'.$supplier->id.'/products', [
            'name' => 'Catalog Only Item',
            'catalog_cost' => 5.5,
        ])->assertStatus(201);

        $this->postJson('/api/v1/suppliers/'.$supplier->id.'/products', [
            'product_id' => $product->id,
            'catalog_cost' => 2.25,
        ])->assertStatus(201);

        $supplier->load(['suppliedProducts', 'importedProducts']);

        $this->assertSame(2, $supplier->suppliedProducts->count());
        $this->assertSame(1, $supplier->importedProducts->count());
        $this->assertSame($product->id, $supplier->importedProducts->first()->product_id);
        $this->assertSame('Catalog Only Item', $supplier->suppliedProducts
            ->firstWhere('product_id', null)->name);
    }

    public function test_supplier_store_normalizes_phone_and_saves_tax_number(): void
    {
        $response = $this->postJson('/api/v1/suppliers', [
            'name' => 'Jordan Distributors',
            'contact_name' => 'Ali',
            'email' => 'ali@distributors.com',
            'phone' => '0785555555',
            'tax_number' => 'JO-201234567',
            'payment_terms' => 'Net 30',
        ]);

        $response->assertStatus(201);

        $supplier = Supplier::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame('+962785555555', $supplier->phone);
        $this->assertSame('JO-201234567', $supplier->tax_number);
        $this->assertSame('Net 30', $supplier->payment_terms);
    }

    public function test_supplier_store_rejects_invalid_phone(): void
    {
        $response = $this->postJson('/api/v1/suppliers', [
            'name' => 'Bad Phone Supplier',
            'phone' => '123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone');
    }

    public function test_po_creation_is_draft_and_touches_no_inventory_or_gl(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 10, 'unit_cost' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame('draft', $response->json('status'));
        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
        $this->assertSame(0, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }

    public function test_po_approval_touches_no_inventory_or_gl(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier);
        $po->update(['status' => 'draft']);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", ['status' => 'ordered']);

        $response->assertStatus(200);
        $this->assertSame('ordered', $response->json('status'));
        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
        $this->assertSame(0, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }

    public function test_po_items_cannot_be_edited_after_approval(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'items' => [
                ['product_id' => $product->id, 'name' => 'Changed', 'quantity' => 20, 'unit_cost' => 3],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, $po->items()->count());
    }

    public function test_po_rejects_invalid_status_transitions(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier);

        $backToDraft = $this->putJson("/api/v1/purchase-orders/{$po->id}", ['status' => 'draft']);
        $backToDraft->assertStatus(422);

        $received = $this->putJson("/api/v1/purchase-orders/{$po->id}", ['status' => 'received']);
        $received->assertStatus(200);

        $afterReceived = $this->putJson("/api/v1/purchase-orders/{$po->id}", ['status' => 'cancelled']);
        $afterReceived->assertStatus(422);
    }

    public function test_partial_grn_marks_po_partially_received_then_received(): void
    {
        $product = $this->makeProduct(hasBatch: false);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier, quantity: 10, unitCost: 2);
        $poItem = $po->items()->firstOrFail();

        $first = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'product_id' => $product->id, 'received_quantity' => 4],
            ],
        ]);

        $first->assertStatus(201);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(4.0, (float) $product->fresh()->stock_quantity);

        $firstEntries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $firstEntries);
        $this->assertSame(8.0, $this->lineBalanceOf($firstEntries->first()->id, $this->accountId('1030')));
        $this->assertSame(-8.0, $this->lineBalanceOf($firstEntries->first()->id, $this->accountId('2010')));

        $second = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'product_id' => $product->id, 'received_quantity' => 6],
            ],
        ]);

        $second->assertStatus(201);
        $this->assertSame('received', $po->fresh()->status);
        $this->assertNotNull($po->fresh()->received_at);
        $this->assertSame(10.0, (float) $product->fresh()->stock_quantity);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(2, $entries);
        $this->assertSame(8.0, $this->lineBalanceOf($entries->get(0)->id, $this->accountId('1030')));
        $this->assertSame(12.0, $this->lineBalanceOf($entries->get(1)->id, $this->accountId('1030')));

        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
        $this->assertSame(20.0, $state['debit']);
    }

    public function test_grn_rejects_draft_po(): void
    {
        $product = $this->makeProduct(hasBatch: false);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier);
        $po->update(['status' => 'draft']);
        $poItem = $po->items()->firstOrFail();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'product_id' => $product->id, 'received_quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
        $this->assertSame(0, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }

    public function test_grn_rejects_over_receipt(): void
    {
        $product = $this->makeProduct(hasBatch: false);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier, quantity: 10);
        $poItem = $po->items()->firstOrFail();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'product_id' => $product->id, 'received_quantity' => 11],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
    }

    public function test_grn_rejects_item_not_on_po(): void
    {
        $productA = $this->makeProduct(hasBatch: false);
        $productB = $this->makeProduct(hasBatch: false);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($productA, $supplier);
        $otherPo = $this->makeOrderedPo($productB, $supplier);
        $foreignItem = $otherPo->items()->firstOrFail();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $foreignItem->id, 'product_id' => $productB->id, 'received_quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame('0.00', $po->items()->firstOrFail()->received_quantity);
    }

    public function test_batch_grn_partial_receive_sets_partially_received_then_received(): void
    {
        $product = $this->makeProduct(hasBatch: true);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier, quantity: 10, unitCost: 2);

        $first = $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 4,
                    'batch_number' => 'PARTIAL-A',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 8,
                ],
            ],
            'payment_method' => 'credit',
        ]);

        $first->assertStatus(201);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame('4.00', $po->items()->firstOrFail()->received_quantity);
        $this->assertSame(4.0, (float) $product->fresh()->stock_quantity);
        $this->assertSame(1, $this->entriesOfType('goods_receipt')->count());

        $second = $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 6,
                    'batch_number' => 'PARTIAL-B',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 12,
                ],
            ],
            'payment_method' => 'credit',
        ]);

        $second->assertStatus(201);
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('10.00', $po->items()->firstOrFail()->received_quantity);
        $this->assertSame(10.0, (float) $product->fresh()->stock_quantity);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(2, $entries);
        $this->assertSame(8.0, $this->lineBalanceOf($entries->get(0)->id, $this->accountId('1030')));
        $this->assertSame(12.0, $this->lineBalanceOf($entries->get(1)->id, $this->accountId('1030')));
        $this->assertSame(-20.0, $this->lineBalanceOf($entries->get(0)->id, $this->accountId('2010')) + $this->lineBalanceOf($entries->get(1)->id, $this->accountId('2010')));

        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
    }

    public function test_grn_batch_product_requires_expiry_date_and_creates_batch(): void
    {
        $product = $this->makeProduct(hasBatch: true);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier, quantity: 10, unitCost: 2);
        $poItem = $po->items()->firstOrFail();

        $missing = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'product_id' => $product->id, 'received_quantity' => 4],
            ],
        ]);

        $missing->assertStatus(422);
        $missing->assertJson(['message' => 'Expiry date is required for batch-managed product Purchased Product.']);
        $this->assertSame(0, ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
        $this->assertSame('0.00', $poItem->fresh()->received_quantity);

        $ok = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'received_quantity' => 4,
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                ],
            ],
        ]);

        $ok->assertStatus(201);
        $batch = ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame(4.0, (float) $batch->quantity);
        $this->assertSame('4.00', $poItem->fresh()->received_quantity);
        $this->assertSame(4.0, (float) $product->fresh()->stock_quantity);
        $this->assertSame(8.0, $this->lineBalanceOf($this->entriesOfType('goods_receipt')->first()->id, $this->accountId('1030')));
    }

    public function test_batch_grn_rejects_over_receipt(): void
    {
        $product = $this->makeProduct(hasBatch: true);
        $supplier = $this->makeSupplier();
        $po = $this->makeOrderedPo($product, $supplier, quantity: 10);

        $response = $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 11,
                    'batch_number' => 'OVER-11',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 22,
                ],
            ],
            'payment_method' => 'credit',
        ]);

        $response->assertStatus(422);
        $this->assertSame('0.00', $po->items()->firstOrFail()->received_quantity);
        $this->assertSame(0, ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }

    public function test_supplier_catalog_adds_and_lists_existing_product(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson("/api/v1/suppliers/{$supplier->id}/products", [
            'product_id' => $product->id,
            'catalog_cost' => 1.75,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'product_id' => $product->id,
            'name' => $product->name,
            'is_imported' => true,
        ]);

        $list = $this->getJson("/api/v1/suppliers/{$supplier->id}/products");
        $list->assertStatus(200);
        $list->assertJsonCount(1);
        $list->assertJsonFragment(['product_id' => $product->id, 'is_imported' => true]);
    }

    public function test_supplier_catalog_adds_catalog_only_item(): void
    {
        $supplier = $this->makeSupplier();

        $response = $this->postJson("/api/v1/suppliers/{$supplier->id}/products", [
            'name' => 'Catalog Only Item',
            'catalog_cost' => 3.5,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'product_id' => null,
            'name' => 'Catalog Only Item',
            'is_imported' => false,
        ]);

        $this->assertNull(SupplierProduct::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->firstOrFail()->product_id);
    }

    public function test_supplier_catalog_removes_item(): void
    {
        $supplier = $this->makeSupplier();
        $item = SupplierProduct::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'name' => 'To Remove',
            'is_imported' => false,
        ]);

        $response = $this->deleteJson("/api/v1/suppliers/{$supplier->id}/products/{$item->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('supplier_products', ['id' => $item->id]);
    }

    public function test_supplier_catalog_is_tenant_scoped(): void
    {
        $otherBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => BusinessType::where('slug', 'supermarket')->firstOrFail()->id,
            'name' => 'Other Business',
            'slug' => 'other-business',
            'status' => 'active',
        ]);
        $otherSupplier = Supplier::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Supplier',
            'is_active' => true,
        ]);

        $this->getJson("/api/v1/suppliers/{$otherSupplier->id}/products")->assertStatus(404);
        $this->postJson("/api/v1/suppliers/{$otherSupplier->id}/products", ['name' => 'Sneaky'])->assertStatus(404);
        $this->assertSame(0, SupplierProduct::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }

    public function test_po_import_creates_product_and_links_catalog(): void
    {
        $supplier = $this->makeSupplier();
        $countBefore = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => null, 'name' => 'Imported Widget', 'quantity' => 5, 'unit_cost' => 4.5, 'import_product' => true],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame('draft', $response->json('status'));

        $products = Product::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->get();
        $this->assertCount($countBefore + 1, $products);

        $product = $products->where('name', 'Imported Widget')->first();
        $this->assertNotNull($product);
        $this->assertSame(4.5, (float) $product->cost);
        $this->assertSame(13.5, (float) $product->price);
        $this->assertTrue((bool) $product->is_active);
        $this->assertSame(0.0, (float) $product->stock_quantity);

        $poItem = PurchaseOrderItem::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame($product->id, $poItem->product_id);
        $this->assertSame('Imported Widget', $poItem->name);

        $pivot = SupplierProduct::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame($supplier->id, $pivot->supplier_id);
        $this->assertSame($product->id, $pivot->product_id);
        $this->assertTrue((bool) $pivot->is_imported);
    }

    public function test_po_rejects_item_without_product_or_import_flag(): void
    {
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => null, 'name' => 'Orphan Item', 'quantity' => 1, 'unit_cost' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.product_id');
        $this->assertSame(0, PurchaseOrder::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());
    }
}
