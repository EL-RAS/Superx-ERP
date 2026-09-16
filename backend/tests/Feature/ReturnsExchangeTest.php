<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ReturnExchange;
use App\Models\User;
use App\Scopes\BusinessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Returns & Exchanges (clothing vertical):
 *  - returnable preview per invoice line (original minus already returned)
 *  - cash/credit returns restock inventory, reverse COGS + revenue, and post
 *    balanced, POSTED journal entries
 *  - exchanges create a new full-value invoice, absorb the trade-in credit,
 *    collect the price difference (or refund the surplus), all GL-net-zero
 */
class ReturnsExchangeTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'clothing_apparel',
            'name_en' => 'Clothing & Apparel',
            'name_ar' => 'ملابس',
            'allowed_modules' => ['sales', 'pos', 'inventory', 'accounting', 'crm', 'returns_exchanges'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Fashion Returns',
            'slug' => 'fashion-returns',
            'status' => 'active',
            'settings' => ['allow_credit_sales' => true],
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'username' => 'returns-admin',
            'email' => 'returns@example.com',
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
            'name' => 'Classic Tee',
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

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Returning Customer',
        ]);
    }

    /**
     * POS cash sale of $qty units of $product at 10 JOD + 5% tax.
     */
    private function makeCashSale(Product $product, float $qty, ?int $customerId = null): array
    {
        $payload = [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ];
        if ($customerId) {
            $payload['customer_id'] = $customerId;
        }

        $response = $this->postJson('/api/v1/invoices', $payload);
        $response->assertStatus(201);

        return $response->json();
    }

    private function accountId(string $code): ?int
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

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

    public function test_returnable_preview_tracks_returned_quantities(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 3);
        $invoiceId = $invoice['id'];
        $itemId = $invoice['items'][0]['id'];

        $preview = $this->getJson("/api/v1/invoices/{$invoiceId}/returnable");
        $preview->assertOk();
        $this->assertSame(3.0, round((float) $preview->json('items.0.quantity'), 2));
        $this->assertSame(3.0, round((float) $preview->json('items.0.returnable'), 2));
        $this->assertSame(0.0, round((float) $preview->json('items.0.already_returned'), 2));

        // Return 1 unit, preview should show 2 left.
        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoiceId,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1, 'reason' => 'wrong size']],
        ])->assertStatus(201);

        $preview = $this->getJson("/api/v1/invoices/{$invoiceId}/returnable");
        $preview->assertOk();
        $this->assertSame(1.0, round((float) $preview->json('items.0.already_returned'), 2));
        $this->assertSame(2.0, round((float) $preview->json('items.0.returnable'), 2));
    }

    public function test_cash_return_restocks_reverses_and_refunds(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 2, $customer->id);
        $itemId = $invoice['items'][0]['id'];

        // Sold 2 (batch quantity_sold = 2, stock 8); return 1 unit.
        $this->assertSame(8.0, round((float) $product->fresh()->stock_quantity, 2));
        $this->assertSame(2.0, round((float) $batch->fresh()->quantity_sold, 2));

        $return = $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1, 'reason' => 'defective']],
        ]);
        $return->assertStatus(201);
        $this->assertSame('return', $return->json('type'));
        $this->assertSame('cash', $return->json('refund_method'));
        $this->assertSame(10.5, round((float) $return->json('returned_amount'), 2));

        // Stock restored: batch quantity_sold 2 → 1, product stock 8 → 9.
        $this->assertSame(1.0, round((float) $batch->fresh()->quantity_sold, 2));
        $this->assertSame(9.0, round((float) $product->fresh()->stock_quantity, 2));

        // Restock leg: Dr 1030 (4) / Cr 5010 (4) — reverses COGS.
        $adjEntries = $this->entriesOfType('inventory_adjustment');
        $this->assertCount(1, $adjEntries);
        $this->assertSame(4.0, $this->lineBalance($adjEntries->first()->id, $this->accountId('1030')));
        $this->assertSame(-4.0, $this->lineBalance($adjEntries->first()->id, $this->accountId('5010')));

        // Revenue reversal + cash refund: Dr 4010 (10) + Dr 2020 (0.5) / Cr 1005 (10.5).
        $returnEntries = $this->entriesOfType('sales_return');
        $this->assertCount(1, $returnEntries);
        $this->assertSame(10.0, $this->lineBalance($returnEntries->first()->id, $this->accountId('4010')));
        $this->assertSame(0.5, $this->lineBalance($returnEntries->first()->id, $this->accountId('2020')));
        $this->assertSame(-10.5, $this->lineBalance($returnEntries->first()->id, $this->accountId('1005')));

        // AR cleared (invoice fully paid, return reverses revenue not receivable).
        $ar = $this->getJson('/api/v1/accounting/ar');
        $ar->assertOk();
        $this->assertSame(0.0, round((float) $ar->json('total_outstanding'), 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_return_cannot_exceed_remaining_quantity(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 2);
        $itemId = $invoice['items'][0]['id'];

        // Return the full 2 units.
        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 2]],
        ])->assertStatus(201);

        // A second return of any quantity is now over the remaining 0.
        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertStatus(422);

        // An oversized single return is rejected too.
        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 3]],
        ])->assertStatus(422);
    }

    public function test_credit_return_reduces_receivable(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $credit = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [
                ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ]);
        $credit->assertStatus(201);
        $invoiceId = $credit->json('id');
        $itemId = $credit->json('items.0.id');

        // Receivable open at 10.50 before the return.
        $this->assertSame(10.5, round((float) $this->getJson('/api/v1/accounting/ar')->json('total_outstanding'), 2));

        // Credit return: Dr 4010 + Dr 2020 / Cr 1040 — receivable falls to zero.
        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoiceId,
            'refund_method' => 'credit',
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
        ])->assertStatus(201);

        $returnEntries = $this->entriesOfType('sales_return');
        $this->assertCount(1, $returnEntries);
        $this->assertSame(-10.5, $this->lineBalance($returnEntries->first()->id, $this->accountId('1040')));

        $ar = $this->getJson('/api/v1/accounting/ar');
        $ar->assertOk();
        $this->assertSame(0.0, round((float) $ar->json('total_outstanding'), 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_equal_value_exchange_creates_invoice_and_trade_in_credit(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 2, $customer->id);
        $itemId = $invoice['items'][0]['id'];

        // Swap 2 units for 2 units of the same product (pure size swap).
        $exchange = $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'exchange',
            'invoice_id' => $invoice['id'],
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 2, 'reason' => 'wrong size']],
            'exchange_items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5],
            ],
        ]);
        $exchange->assertStatus(201);
        $this->assertSame('exchange', $exchange->json('type'));
        $this->assertSame(21.0, round((float) $exchange->json('returned_amount'), 2));
        $this->assertSame(21.0, round((float) $exchange->json('exchanged_amount'), 2));
        $this->assertSame(0.0, round((float) $exchange->json('difference_amount'), 2));
        $this->assertNotNull($exchange->json('exchange_invoice_id'));

        // New invoice fully paid by the trade-in credit (no cash difference).
        $newInvoiceId = $exchange->json('exchange_invoice_id');
        $this->assertDatabaseHas('invoices', ['id' => $newInvoiceId, 'payment_status' => 'paid', 'net_amount' => 21.0]);

        $creditPayment = Payment::where('invoice_id', $newInvoiceId)->where('method', 'credit')->first();
        $this->assertNotNull($creditPayment);
        $this->assertSame(21.0, round((float) $creditPayment->amount, 2));

        // Zero-net trade-in credit entry exists and is posted/balanced:
        // Dr 1040 (21.0) / Cr 1040 (21.0).
        $creditEntries = $this->entriesOfType('exchange_credit');
        $this->assertCount(1, $creditEntries);
        $creditEntryId = $creditEntries->first()->id;
        $this->assertSame(21.0, round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $creditEntryId)->sum('debit'), 2));
        $this->assertSame(21.0, round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $creditEntryId)->sum('credit'), 2));
        $this->assertSame(0.0, $this->lineBalance($creditEntryId, $this->accountId('1040')));

        // No difference payment posted for an equal swap: the exchange invoice
        // carries only the trade-in credit (exchange_credit entry), never an
        // invoice_payment entry.
        $newInvoicePaymentIds = Payment::where('invoice_id', $newInvoiceId)->pluck('id');
        $this->assertCount(0, $this->entriesOfType('invoice_payment')
            ->filter(fn ($entry) => $newInvoicePaymentIds->contains($entry->reference_id)));

        // Net stock effect: 10 units on hand, 2 re-deducted for the exchange.
        $this->assertSame(8.0, round((float) $product->fresh()->stock_quantity, 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_exchange_with_price_difference_collects_payment(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 1, $customer->id);
        $itemId = $invoice['items'][0]['id'];

        // Return 1 unit (10.50 credit), take 2 units (21.00) → pay the 10.50 difference.
        $exchange = $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'exchange',
            'invoice_id' => $invoice['id'],
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
            'exchange_items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 5],
            ],
            'exchange_difference_method' => 'cash',
        ]);
        $exchange->assertStatus(201);
        $this->assertSame(10.5, round((float) $exchange->json('returned_amount'), 2));
        $this->assertSame(21.0, round((float) $exchange->json('exchanged_amount'), 2));
        $this->assertSame(10.5, round((float) $exchange->json('difference_amount'), 2));

        // The difference is collected as a cash payment (Dr 1005 / Cr 1040).
        $diffPayments = Payment::where('invoice_id', $exchange->json('exchange_invoice_id'))
            ->where('method', 'cash')
            ->get();
        $this->assertCount(1, $diffPayments);
        $this->assertSame(10.5, round((float) $diffPayments->first()->amount, 2));

        // The diff payment posts its own invoice_payment entry (the original
        // sale's cash payment already has one).
        $paymentEntries = $this->entriesOfType('invoice_payment');
        $this->assertCount(2, $paymentEntries);
        $diffEntry = $paymentEntries->firstWhere('reference_id', $diffPayments->first()->id);
        $this->assertNotNull($diffEntry);
        $this->assertSame(10.5, $this->lineBalance($diffEntry->id, $this->accountId('1005')));
        $this->assertSame(-10.5, $this->lineBalance($diffEntry->id, $this->accountId('1040')));

        // New invoice reads as fully paid; AR net zero.
        $this->assertSame('paid', Payment::firstWhere('invoice_id', $exchange->json('exchange_invoice_id'))->invoice->payment_status);
        $ar = $this->getJson('/api/v1/accounting/ar');
        $ar->assertOk();
        $this->assertSame(0.0, round((float) $ar->json('total_outstanding'), 2));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_exchange_surplus_is_refunded(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 2, $customer->id);
        $itemId = $invoice['items'][0]['id'];

        // Return 2 units (21.00 credit), take 1 unit (10.50) → refund the 10.50 surplus.
        $exchange = $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'exchange',
            'invoice_id' => $invoice['id'],
            'items' => [['invoice_item_id' => $itemId, 'quantity' => 2]],
            'exchange_items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5],
            ],
            'refund_method' => 'cash',
        ]);
        $exchange->assertStatus(201);
        $this->assertSame(21.0, round((float) $exchange->json('returned_amount'), 2));
        $this->assertSame(10.5, round((float) $exchange->json('exchanged_amount'), 2));
        $this->assertSame(-10.5, round((float) $exchange->json('difference_amount'), 2));
        $this->assertSame(10.5, round((float) $exchange->json('refund_amount'), 2));

        // Surplus refund: Dr 1040 (10.5) / Cr 1005 (10.5).
        $surplusEntries = $this->entriesOfType('exchange_surplus');
        $this->assertCount(1, $surplusEntries);
        $this->assertSame(10.5, $this->lineBalance($surplusEntries->first()->id, $this->accountId('1040')));
        $this->assertSame(-10.5, $this->lineBalance($surplusEntries->first()->id, $this->accountId('1005')));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_returns_are_listed_and_tenant_scoped(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 1);

        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $invoice['id'],
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $invoice['items'][0]['id'], 'quantity' => 1]],
        ])->assertStatus(201);

        $this->getJson('/api/v1/returns-exchanges')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'return');

        // Foreign business invoice → returnable returns 404 and return is blocked.
        $otherBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Other Shop',
            'slug' => 'other-shop',
            'status' => 'active',
        ]);
        $foreignInvoice = $this->makeCashSale($product, 1);
        $foreignInvoice = \App\Models\Invoice::find($foreignInvoice['id']);
        $foreignInvoice->update(['business_id' => $otherBusiness->id]);

        $this->getJson("/api/v1/invoices/{$foreignInvoice->id}/returnable")->assertNotFound();

        $this->postJson('/api/v1/returns-exchanges', [
            'type' => 'return',
            'invoice_id' => $foreignInvoice->id,
            'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $foreignInvoice->items()->first()->id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_voided_invoice_cannot_be_returned(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);
        $invoice = $this->makeCashSale($product, 1);

        $this->postJson("/api/v1/invoices/{$invoice['id']}/void")->assertOk();
        $this->getJson("/api/v1/invoices/{$invoice['id']}/returnable")->assertStatus(422);
    }
}
