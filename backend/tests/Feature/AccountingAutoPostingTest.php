<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
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

/**
 * System integration verification: every business action must automatically
 * generate a POSTED, BALANCED double-entry journal entry.
 *
 * Hooks covered:
 *  1. Cash sale      → sale entry (Dr 1040 / Cr 4010 / Cr 2020 + COGS Dr 5010 / Cr 1030)
 *                       + payment entry (Dr 1005-1010 / Cr 1040). Net: cash in,
 *                       revenue, tax payable, COGS recognized.
 *  2. Credit sale    → sale entry only; Dr 1040 remains outstanding.
 *  3. Goods receipt  → Dr 1030 / Cr 2010 (credit) with payment-method routing.
 *  4. Supplier pay   → Dr 2010 / Cr 1005/1020 (cash/bank).
 *  5. AR collection  → Dr 1005/1010 / Cr 1040.
 *  6. Waste/damage   → Dr 5020 / Cr 1030; count surplus → Dr 1030 / Cr 4030.
 */
class AccountingAutoPostingTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Auto Posting Retail',
            'slug' => 'auto-posting-retail',
            'status' => 'active',
            'settings' => ['allow_credit_sales' => true],
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'auto-posting-user',
            'email' => 'auto-posting@example.com',
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
            'name' => 'Auto Product',
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
            'name' => 'Auto Supplier',
            'is_active' => true,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Auto Customer',
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
     * Every automated hook must yield a POSTED entry whose debit equals credit.
     */
    private function assertAllEntriesPostedAndBalanced(): void
    {
        $entries = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();

        $this->assertNotEmpty($entries, 'No journal entries were generated.');

        foreach ($entries as $entry) {
            $this->assertTrue((bool) $entry->is_posted, "Entry {$entry->entry_number} must be posted.");
            $this->assertNotSame('draft', $entry->status, "Entry {$entry->entry_number} must not be draft.");
        }

        // Aggregate GL-wide invariant: total debits === total credits.
        $debit = round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->sum('debit'), 2);
        $credit = round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->sum('credit'), 2);

        $this->assertSame($credit, $debit, 'Ledger must balance globally.');
    }

    private function entriesOfType(string $type): Collection
    {
        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', $type)
            ->orderBy('id')
            ->get();
    }

    private function lineBalance(int $entryId, int $accountId): float
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

    public function test_cash_sale_posts_balanced_sale_and_payment_entries(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10, 4.0);

        // 2 units at 10 JOD + 5% tax, paid cash at POS.
        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Auto Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $sale->assertStatus(201);

        // Sale entry: Dr 1040 (21) / Cr 4010 (20) / Cr 2020 (1) + COGS Dr 5010 (8) / Cr 1030 (8).
        $saleEntries = $this->entriesOfType('sale');
        $this->assertCount(1, $saleEntries);
        $this->assertSame(21.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('1040')));
        $this->assertSame(-20.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('4010')));
        $this->assertSame(-1.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('2020')));
        $this->assertSame(8.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('5010')));
        $this->assertSame(-8.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('1030')));

        // Payment entry (no open shift → safe): Dr 1005 (21) / Cr 1040 (21) nets the AR out.
        $paymentEntries = $this->entriesOfType('invoice_payment');
        $this->assertCount(1, $paymentEntries);
        $this->assertSame(21.0, $this->lineBalance($paymentEntries->first()->id, $this->accountId('1005')));
        $this->assertSame(-21.0, $this->lineBalance($paymentEntries->first()->id, $this->accountId('1040')));

        // Net effect across the two entries: cash +21, revenue -20, tax -1, COGS +8, inventory -8.
        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_credit_sale_posts_sale_entry_but_leaves_receivable_open(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeBatch($product, 10, 4.0);

        // Credit sale: no cash payment, receivable stays outstanding.
        $credit = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Auto Product',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $credit->assertStatus(201);
        $invoiceId = $credit->json('id');

        // Sale entry only; no invoice_payment entry yet.
        $saleEntries = $this->entriesOfType('sale');
        $this->assertCount(1, $saleEntries);
        $this->assertSame(10.5, $this->lineBalance($saleEntries->first()->id, $this->accountId('1040')));
        $this->assertSame(-10.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('4010')));
        $this->assertSame(-0.5, $this->lineBalance($saleEntries->first()->id, $this->accountId('2020')));
        // COGS 1 × 4 = 4.
        $this->assertSame(4.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('5010')));
        $this->assertSame(-4.0, $this->lineBalance($saleEntries->first()->id, $this->accountId('1030')));
        $this->assertCount(0, $this->entriesOfType('invoice_payment'));

        // AR subledger shows the customer outstanding at 10.50.
        $ar = $this->getJson('/api/v1/accounting/ar');
        $ar->assertOk();
        $this->assertSame(10.5, round((float) $ar->json('total_outstanding'), 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_ar_collection_clears_receivable_with_balanced_entry(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeBatch($product, 10, 4.0);

        $credit = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Auto Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $credit->assertStatus(201);
        $invoiceId = $credit->json('id');

        // Customer settles the debt: Dr 1005 / Cr 1040.
        $pay = $this->postJson("/api/v1/accounting/ar/{$invoiceId}/pay", [
            'amount' => 21,
            'method' => 'cash',
        ]);
        $pay->assertStatus(201);

        $paymentEntries = $this->entriesOfType('invoice_payment');
        $this->assertCount(1, $paymentEntries);
        $this->assertSame(21.0, $this->lineBalance($paymentEntries->first()->id, $this->accountId('1005')));
        $this->assertSame(-21.0, $this->lineBalance($paymentEntries->first()->id, $this->accountId('1040')));

        // AR cleared.
        $ar = $this->getJson('/api/v1/accounting/ar');
        $ar->assertOk();
        $this->assertSame(0.0, round((float) $ar->json('total_outstanding'), 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_goods_receipt_and_supplier_payment_post_balanced_entries(): void
    {
        $product = $this->makeProduct(['has_batch' => true]);
        $supplier = $this->makeSupplier();
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-AUTO-1',
            'status' => 'ordered',
            'total_amount' => 40,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 10,
            'unit_cost' => 4,
            'total' => 40,
        ]);

        // Receive on credit → Dr 1030 (40) / Cr 2010 (40).
        $grn = $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'batch_number' => 'GRN-AUTO-001',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 40,
                ],
            ],
            'payment_method' => 'credit',
        ]);
        $grn->assertStatus(201);

        $grnEntries = $this->entriesOfType('goods_receipt');
        $this->assertCount(1, $grnEntries);
        $this->assertSame(40.0, $this->lineBalance($grnEntries->first()->id, $this->accountId('1030')));
        $this->assertSame(-40.0, $this->lineBalance($grnEntries->first()->id, $this->accountId('2010')));

        // Pay the supplier (bank) → Dr 2010 (40) / Cr 1020 (40).
        $pay = $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 40,
            'method' => 'bank_transfer',
        ]);
        $pay->assertStatus(201);

        $supplierPaymentEntries = $this->entriesOfType('supplier_payment');
        $this->assertCount(1, $supplierPaymentEntries);
        $this->assertSame(40.0, $this->lineBalance($supplierPaymentEntries->first()->id, $this->accountId('2010')));
        $this->assertSame(-40.0, $this->lineBalance($supplierPaymentEntries->first()->id, $this->accountId('1020')));

        // Payable now zero.
        $ap = $this->getJson('/api/v1/accounting/ap');
        $ap->assertOk();
        $this->assertSame(0.0, round((float) $ap->json('total_outstanding'), 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_inventory_adjustments_route_to_waste_expense_and_adjustment_gain(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 10, 4.0);

        // Waste 2 units (2 × 4 = 8) → Dr 5020 (8) / Cr 1030 (8).
        $waste = $this->postJson('/api/v1/inventory-adjustments', [
            'type' => 'waste',
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => 2,
        ]);
        $waste->assertStatus(201);

        $adjustments = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(1, $adjustments);
        $this->assertSame(8.0, $this->lineBalance($adjustments->first()->id, $this->accountId('5020')));
        $this->assertSame(-8.0, $this->lineBalance($adjustments->first()->id, $this->accountId('1030')));

        // Count surplus 3 units → Dr 1030 (12) / Cr 4030 (12).
        $surplus = $this->postJson('/api/v1/inventory-adjustments', [
            'type' => 'count_surplus',
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => 3,
            'unit_cost' => 4,
        ]);
        $surplus->assertStatus(201);

        $adjustments = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(2, $adjustments);
        $this->assertSame(12.0, $this->lineBalance($adjustments->last()->id, $this->accountId('1030')));
        $this->assertSame(-12.0, $this->lineBalance($adjustments->last()->id, $this->accountId('4030')));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_full_lifecycle_produces_balanced_posted_journal(): void
    {
        $product = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $customer = $this->makeCustomer();

        // Hook: goods receipt (credit) 10 units × 4 = 40.
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-AUTO-2',
            'status' => 'ordered',
            'total_amount' => 40,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 10,
            'unit_cost' => 4,
            'total' => 40,
        ]);
        $this->postJson('/api/v1/product-batches/grn-receive', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'batch_number' => 'GRN-AUTO-2',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 40,
                ],
            ],
            'payment_method' => 'credit',
        ])->assertStatus(201);

        // Hook: supplier payment (cash safe) 40.
        $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 40,
            'method' => 'cash',
        ])->assertStatus(201);

        // Hook: cash POS sale 2 units (net 21) + COGS 8.
        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Auto Product', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ])->assertStatus(201);

        // Hook: credit sale 1 unit (net 10.5) + COGS 4.
        $credit = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Auto Product', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ]);
        $credit->assertStatus(201);

        // Hook: AR collection 10.50.
        $this->postJson("/api/v1/accounting/ar/{$credit->json('id')}/pay", [
            'amount' => 10.5,
            'method' => 'cash',
        ])->assertStatus(201);

        // Hook: waste 1 unit (cost 4).
        $batch = ProductBatch::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->first();
        $this->postJson('/api/v1/inventory-adjustments', [
            'type' => 'waste',
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => 1,
        ])->assertStatus(201);

        // 8 hooks fired: goods_receipt, supplier_payment, sale ×2, invoice_payment ×2,
        // inventory_adjustment = 7 distinct entries. Every one must be posted + balanced,
        // and the ledger-wide totals must match.
        $this->assertSame(7, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());

        $this->assertAllEntriesPostedAndBalanced();
    }
}
