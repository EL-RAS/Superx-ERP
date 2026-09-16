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

class InventoryAccountingIntegrationTest extends TestCase
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
            'name' => 'Test Retail',
            'slug' => 'test-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'test-user',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Test Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 5,
            'has_batch' => true,
            'is_active' => true,
            'stock_quantity' => 0,
        ], $overrides));
    }

    private function makeBatch(Product $product, float $quantity, float $costPerUnit = 4.0): ProductBatch
    {
        return ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'B-'.strtoupper(Str::random(6)),
            'quantity' => $quantity,
            'quantity_sold' => 0,
            'quantity_returned' => 0,
            'cost_per_unit' => $costPerUnit,
            'total_cost' => round($quantity * $costPerUnit, 2),
            'received_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Test Supplier',
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
     * @return array{entries: Collection<int, JournalEntry>, lines: Collection<int, JournalEntryLine>, debit: float, credit: float}
     */
    private function journalState(): array
    {
        $entries = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->orderBy('id')
            ->get();

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();

        return [
            'entries' => $entries,
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

    public function test_goods_receipt_credit_posts_inventory_against_payables(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'RCV-CREDIT-001',
            'quantity' => 10,
            'total_cost' => 50,
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        $response->assertStatus(201);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $entries);
        $this->assertSame(50.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1030')));
        $this->assertSame(-50.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));

        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
    }

    public function test_goods_receipt_cash_credits_safe_instead_of_payables(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'RCV-CASH-001',
            'quantity' => 10,
            'total_cost' => 50,
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(201);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $entries);
        $this->assertSame(50.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1030')));
        // No open shift → cash lands in the main safe, not payables.
        $this->assertSame(-50.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1005')));
        $this->assertSame(0.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));

        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
    }

    public function test_goods_receipt_bank_credits_bank_account(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'RCV-BANK-001',
            'quantity' => 10,
            'total_cost' => 50,
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'payment_method' => 'bank',
        ]);

        $response->assertStatus(201);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $entries);
        $this->assertSame(-50.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('1020')));
        $this->assertSame(0.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));

        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
    }

    public function test_batch_sell_rejects_insufficient_stock(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 5);

        $response = $this->postJson("/api/v1/product-batches/{$batch->id}/sell", [
            'quantity' => 10,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Insufficient batch stock', $response->json('message'));

        $this->assertSame('0.00', $batch->fresh()->quantity_sold);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_fefo_sale_rejects_insufficient_stock_and_rolls_back(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 5, 4.0);

        $response = $this->postJson('/api/v1/product-batches/sell-fefo', [
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Insufficient batch stock', $response->json('message'));

        $this->assertSame('0.00', ProductBatch::where('business_id', $this->business->id)->value('quantity_sold'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, $this->entriesOfType('fefo_sale')->count());
    }

    public function test_batch_adjust_rejects_negative_over_available(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 5);

        $response = $this->postJson("/api/v1/product-batches/{$batch->id}/adjust", [
            'quantity_adjusted' => -10,
            'reason' => 'stock take',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Cannot adjust more than available quantity', $response->json('message'));

        $this->assertSame('5.00', $batch->fresh()->quantity);
        $this->assertDatabaseCount('inventory_adjustments', 0);
    }

    public function test_grn_receive_with_po_on_credit_skips_direct_payment(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-001',
            'status' => 'ordered',
            'total_amount' => 20,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => 'Test Product',
            'quantity' => 5,
            'unit_cost' => 4,
            'total' => 20,
        ]);

        $response = $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'batch_number' => 'GRN-CREDIT-001',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 20,
                ],
            ],
            'payment_method' => 'credit',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseCount('purchase_order_payments', 0);
        $this->assertSame('received', $po->fresh()->status);

        $entries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $entries);
        $this->assertSame(-20.0, $this->lineBalanceOf($entries->first()->id, $this->accountId('2010')));

        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
    }

    public function test_grn_receive_with_po_on_cash_creates_direct_payment(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-002',
            'status' => 'ordered',
            'total_amount' => 20,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => 'Test Product',
            'quantity' => 5,
            'unit_cost' => 4,
            'total' => 20,
        ]);

        $response = $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'batch_number' => 'GRN-CASH-001',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 20,
                ],
            ],
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseCount('purchase_order_payments', 1);
        $directPayment = PurchaseOrderPayment::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->first();
        $this->assertSame('cash', $directPayment->method);

        // Goods receipt recognizes the payable, supplier payment settles it.
        $grn = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $grn);
        $this->assertSame(-20.0, $this->lineBalanceOf($grn->first()->id, $this->accountId('2010')));

        $payments = $this->entriesOfType('supplier_payment');
        $this->assertCount(1, $payments);
        $this->assertSame(20.0, $this->lineBalanceOf($payments->first()->id, $this->accountId('2010')));
        $this->assertSame(-20.0, $this->lineBalanceOf($payments->first()->id, $this->accountId('1005')));

        // Net effect: inventory 1030 increased by 20, cash (safe) decreased by 20.
        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
        $this->assertSame(20.0, $this->lineBalanceOf($grn->first()->id, $this->accountId('1030')));
    }

    public function test_sales_return_restocks_batch_and_reverses_revenue(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 10, 4.0);

        // Sale of 2 units at 10 JOD + 5% tax.
        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Test Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);

        $sale->assertStatus(201);
        $invoiceId = $sale->json('id');
        $this->assertSame('2.00', $batch->fresh()->quantity_sold);

        // Return both units to the same batch with a cash refund.
        $return = $this->postJson('/api/v1/inventory-adjustments', [
            'type' => 'return',
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => 2,
            'invoice_id' => $invoiceId,
            'unit_cost' => 4,
            'refund_method' => 'cash',
        ]);

        $return->assertStatus(201);

        // Stock restored: quantity_sold back to zero.
        $this->assertSame('0.00', $batch->fresh()->quantity_sold);

        // COGS reversal entry: Dr 1030, Cr 5010 at original unit cost (2 x 4).
        $adjustmentEntries = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(1, $adjustmentEntries);
        $this->assertSame(8.0, $this->lineBalanceOf($adjustmentEntries->first()->id, $this->accountId('1030')));
        $this->assertSame(-8.0, $this->lineBalanceOf($adjustmentEntries->first()->id, $this->accountId('5010')));

        // Sales return reversal entry: Dr 4010 (20), Dr 2020 (1), Cr cash 1005 (21).
        $returnEntries = $this->entriesOfType('sales_return');
        $this->assertCount(1, $returnEntries);
        $this->assertSame(20.0, $this->lineBalanceOf($returnEntries->first()->id, $this->accountId('4010')));
        $this->assertSame(1.0, $this->lineBalanceOf($returnEntries->first()->id, $this->accountId('2020')));
        $this->assertSame(-21.0, $this->lineBalanceOf($returnEntries->first()->id, $this->accountId('1005')));

        // The whole sale + return cycle leaves the GL net-zero.
        $state = $this->journalState();
        $this->assertSame($state['credit'], $state['debit']);
        $this->assertNotCount(0, $state['entries']);
    }
}
