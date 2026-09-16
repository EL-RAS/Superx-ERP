<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementUpgradeTest extends TestCase
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
            'name' => 'Procurement Upgrade Retail',
            'slug' => 'procurement-upgrade-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'procurement-upgrade-user',
            'email' => 'procurement-upgrade@example.com',
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
            'name' => 'Procured Product',
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
            'name' => 'Upgrade Supplier',
            'is_active' => true,
        ]);
    }

    private function accountId(string $code): ?int
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
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

    private function journalState(): array
    {
        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();

        return [
            'debit' => round((float) $lines->sum('debit'), 2),
            'credit' => round((float) $lines->sum('credit'), 2),
        ];
    }

    public function test_direct_goods_receipt_creates_stock_items_and_payable(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'notes' => 'Direct drop',
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 5, 'unit_cost' => 3],
            ],
        ]);

        $response->assertStatus(201);
        $receipt = $response->json();

        $this->assertSame('GRN-1', $receipt['receipt_number']);
        $this->assertSame($supplier->id, $receipt['supplier_id']);
        $this->assertSame(15.0, (float) $receipt['total_amount']);
        $this->assertSame(5.0, (float) $product->fresh()->stock_quantity);

        $this->assertSame(1, GoodsReceiptItem::where('goods_receipt_id', $receipt['id'])->count());
        $item = GoodsReceiptItem::where('goods_receipt_id', $receipt['id'])->firstOrFail();
        $this->assertSame(5.0, (float) $item->quantity);
        $this->assertSame(15.0, (float) $item->total);

        // Credit (absent payment method) → Dr 1030 / Cr 2010.
        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $entries);
        $this->assertSame(15.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1030')));
        $this->assertSame(-15.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));
        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);

        // The supplier now carries a payable in the AP subledger.
        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $supplierRow = collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id);
        $this->assertNotNull($supplierRow);
        $this->assertSame(15.0, (float) $supplierRow['outstanding']);
        $this->assertSame(1, $supplierRow['open_receipts_count']);
    }

    public function test_direct_goods_receipt_paid_cash_credits_safe_and_records_payment(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 10, 'unit_cost' => 2.5],
            ],
        ]);

        $response->assertStatus(201);
        $receiptId = $response->json('id');

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertSame(25.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1030')));
        $this->assertSame(-25.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1005')));
        $this->assertSame(0.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));
        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);

        // Audit row exists but no separate supplier-payment GL entry.
        $payment = PurchaseOrderPayment::where('goods_receipt_id', $receiptId)->firstOrFail();
        $this->assertSame('cash', $payment->method);
        $this->assertCount(0, $this->entriesOfType('supplier_payment'));

        // Fully paid → supplier no longer appears in the AP subledger.
        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $this->assertCount(0, collect($ap['suppliers'])->where('supplier_id', $supplier->id));
    }

    public function test_direct_goods_receipt_requires_supplier(): void
    {
        $product = $this->makeProduct();

        $this->postJson('/api/v1/goods-receipts', [
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 5],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_direct_goods_receipt_stores_receipt_date_and_reference_invoice(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'received_at' => '2026-09-05',
            'reference_invoice_number' => 'SUP-INV-8841',
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 4, 'unit_cost' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame('2026-09-05', substr($response->json('received_at'), 0, 10));
        $this->assertSame('SUP-INV-8841', $response->json('reference_invoice_number'));

        $this->assertSame(
            '2026-09-05',
            GoodsReceipt::findOrFail($response->json('id'))->received_at->toDateString()
        );

        // The reference number is surfaced in the supplier ledger GRN row.
        $ledger = $this->getJson('/api/v1/suppliers/'.$supplier->id.'/ledger')->assertOk()->json();
        $grnRow = collect($ledger['rows'])->firstWhere('kind', 'goods_receipt');
        $this->assertNotNull($grnRow);
        $this->assertStringContainsString('SUP-INV-8841', $grnRow['detail']);
    }

    public function test_direct_goods_receipt_defaults_receipt_date_to_now(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame(now()->toDateString(), substr($response->json('received_at'), 0, 10));
        $this->assertNull($response->json('reference_invoice_number'));
    }

    public function test_direct_goods_receipt_editable_cost_blends_weighted_average(): void
    {
        $product = $this->makeProduct();
        $product->update(['stock_quantity' => 10, 'cost' => 2]);
        $supplier = $this->makeSupplier();

        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 5, 'unit_cost' => 4],
            ],
        ])->assertStatus(201);

        $fresh = $product->fresh();
        $this->assertSame(15.0, (float) $fresh->stock_quantity);
        $this->assertSame(2.67, (float) $fresh->cost);
    }

    public function test_direct_goods_receipt_converts_purchase_units(): void
    {
        $product = $this->makeProduct(hasBatch: true, overrides: [
            'purchase_unit' => 'case',
            'purchase_unit_qty' => 24,
        ]);
        $supplier = $this->makeSupplier();

        $response = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'received_quantity' => 2,
                    'unit_cost' => 30,
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                ],
            ],
        ]);

        $response->assertStatus(201);

        $batch = ProductBatch::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(48.0, (float) $batch->quantity);
        $this->assertSame(1440.0, (float) $batch->total_cost);
        $this->assertSame(30.0, (float) $batch->cost_per_unit);

        $item = GoodsReceiptItem::where('goods_receipt_id', $response->json('id'))->firstOrFail();
        $this->assertSame(48.0, (float) $item->quantity);
        $this->assertSame(2.0, (float) $item->purchase_quantity);
        $this->assertSame(24.0, (float) $item->purchase_unit_qty);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertSame(1440.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1030')));
    }

    public function test_goods_receipt_pay_settles_direct_payable(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $receipt = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 8, 'unit_cost' => 2],
            ],
        ])->assertStatus(201)->json();

        $apBefore = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $this->assertSame(16.0, (float) collect($apBefore['suppliers'])->firstWhere('supplier_id', $supplier->id)['outstanding']);

        $this->postJson("/api/v1/goods-receipts/{$receipt['id']}/pay", [
            'amount' => 16,
            'method' => 'cash',
        ])->assertStatus(201);

        $paymentEntries = $this->entriesOfType('supplier_payment');
        $this->assertCount(1, $paymentEntries);
        $this->assertSame(16.0, $this->lineBalanceOf($paymentEntries->first()->id, $this->accountId('2010')));
        $this->assertSame(-16.0, $this->lineBalanceOf($paymentEntries->first()->id, $this->accountId('1005')));
        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);

        $apAfter = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $this->assertSame(0.0, (float) (collect($apAfter['suppliers'])->firstWhere('supplier_id', $supplier->id)['outstanding'] ?? 0));

        // Supplier card reflects a zero balance too.
        $this->assertSame(0.0, (float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'));
    }

    public function test_purchase_return_posts_debit_note_and_reduces_payable(): void
    {
        $product = $this->makeProduct(hasBatch: true);
        $supplier = $this->makeSupplier();

        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'received_quantity' => 10,
                    'unit_cost' => 2,
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                ],
            ],
        ])->assertStatus(201);

        $batch = ProductBatch::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(10.0, (float) $batch->quantity);

        $this->postJson('/api/v1/inventory-adjustments', [
            'type' => 'purchase_return',
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'supplier_id' => $supplier->id,
            'quantity' => 2,
            'unit_cost' => 2,
            'reason' => 'Damaged on arrival',
        ])->assertStatus(201);

        // Stock removed from the batch.
        $this->assertSame(8.0, (float) $batch->fresh()->quantity - (float) $batch->fresh()->quantity_sold);

        $adjustment = InventoryAdjustment::where('type', 'purchase_return')->firstOrFail();
        $this->assertSame(-2.0, (float) $adjustment->quantity_adjusted);
        $this->assertSame($supplier->id, $adjustment->metadata['supplier_id']);

        // Debit note GL: Dr 2010 / Cr 1030.
        $entries = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(1, $entries);
        $this->assertSame(4.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));
        $this->assertSame(-4.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1030')));
        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);

        // Payable reduced: 20 - 4 = 16.
        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $row = collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id);
        $this->assertSame(16.0, (float) $row['outstanding']);
        $this->assertSame(4.0, (float) $row['returns_total']);
        $this->assertSame(16.0, (float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'));
    }

    public function test_supplier_balance_combines_po_grn_payments_and_returns(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-UPGRADE-1',
            'status' => 'ordered',
            'total_amount' => 20,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 10,
            'unit_cost' => 2,
            'total' => 20,
        ]);

        // An approved PO alone adds nothing; only received goods do.
        $this->assertSame(0.0, (float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'));

        // Direct GRN 15 → payable 15 (PO still has no goods received).
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 5, 'unit_cost' => 3],
            ],
        ])->assertStatus(201);

        $this->assertSame(15.0, (float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'));

        // Pay 10 → 5.
        $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertStatus(201);

        $this->assertSame(5.0, (float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'));
    }

    public function test_supplier_ledger_lists_activity_with_running_balance(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 10, 'unit_cost' => 2],
            ],
        ])->assertStatus(201);

        $receipts = GoodsReceipt::where('supplier_id', $supplier->id)->get();
        $this->postJson("/api/v1/goods-receipts/{$receipts->first()->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/suppliers/'.$supplier->id.'/ledger')->assertOk()->json();

        $this->assertSame(10.0, (float) $response['balance']);
        $kinds = collect($response['rows'])->pluck('kind')->values()->all();
        $this->assertContains('goods_receipt', $kinds);
        $this->assertContains('payment', $kinds);

        // AP convention: the direct GRN increases the payable (Credit 20),
        // the payment reduces it (Debit 10), and the running balance follows.
        $grnRow = collect($response['rows'])->firstWhere('kind', 'goods_receipt');
        $this->assertSame(0.0, (float) $grnRow['debit']);
        $this->assertSame(20.0, (float) $grnRow['credit']);
        $this->assertSame(20.0, (float) $grnRow['balance']);

        $paymentRow = collect($response['rows'])->firstWhere('kind', 'payment');
        $this->assertSame(10.0, (float) $paymentRow['debit']);
        $this->assertSame(0.0, (float) $paymentRow['credit']);
        $this->assertSame(10.0, (float) $paymentRow['balance']);

        $this->assertSame(10.0, (float) collect($response['rows'])->last()['balance']);
    }

    public function test_goods_receipt_pay_rejects_duplicate_payment_and_keeps_balance_positive(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();

        $receipt = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 8, 'unit_cost' => 2],
            ],
        ])->assertStatus(201)->json();

        // Settle the full payable.
        $this->postJson("/api/v1/goods-receipts/{$receipt['id']}/pay", [
            'amount' => 16,
            'method' => 'cash',
        ])->assertStatus(201);

        // Duplicate submission for the same receipt must be rejected.
        $duplicate = $this->postJson("/api/v1/goods-receipts/{$receipt['id']}/pay", [
            'amount' => 16,
            'method' => 'cash',
        ]);
        $duplicate->assertStatus(422);

        // Supplier balance never goes negative — it stays at zero.
        $this->assertSame(0.0, (float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'));

        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $this->assertSame(0.0, (float) (collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id)['outstanding'] ?? 0));
    }

    public function test_grn_index_filters_by_supplier_and_returns_items(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $other = $this->makeSupplier();

        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 3],
            ],
        ])->assertStatus(201);

        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $other->id,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 3],
            ],
        ])->assertStatus(201);

        $list = $this->getJson('/api/v1/goods-receipts?supplier_id='.$supplier->id)->assertOk()->json();
        $this->assertSame(1, $list['total']);
        $this->assertSame($supplier->id, $list['data'][0]['supplier']['id']);
        $this->assertCount(1, $list['data'][0]['items']);
    }

    public function test_cross_business_supplier_ledger_is_404(): void
    {
        $foreignType = BusinessType::create([
            'slug' => 'clinic-'.Str::random(4),
            'name_en' => 'Clinic',
            'name_ar' => 'عيادة',
            'allowed_modules' => ['inventory', 'pos'],
        ]);
        $foreignBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $foreignType->id,
            'name' => 'Foreign Business',
            'slug' => 'foreign-'.Str::random(4),
            'status' => 'active',
        ]);

        $foreignSupplier = Supplier::create([
            'business_id' => $foreignBusiness->id,
            'name' => 'Foreign Supplier',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/suppliers/'.$foreignSupplier->id.'/ledger')->assertStatus(404);
        $this->postJson('/api/v1/goods-receipts/999999/pay', ['amount' => 1, 'method' => 'cash'])->assertStatus(404);
    }
}
