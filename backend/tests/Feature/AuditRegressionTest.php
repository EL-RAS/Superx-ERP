<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Promotion;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditRegressionTest extends TestCase
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
            'allowed_modules' => ['inventory', 'pos'],
        ]);

        $this->business = $this->makeBusiness('audit-test-retail');
        $this->user = $this->makeUser($this->business);

        Sanctum::actingAs($this->user);
    }

    private function makeBusiness(string $slug): Business
    {
        return Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function makeUser(Business $business, string $suffix = ''): User
    {
        return User::create([
            'business_id' => $business->id,
            'name' => 'User '.$suffix,
            'username' => 'user-'.$suffix.Str::random(4),
            'email' => $suffix.'test-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Audit Product',
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

    private function accountId(string $code): ?int
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

    private function entriesOfType(string $referenceType)
    {
        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', $referenceType)
            ->orderBy('id')
            ->get();
    }

    public function test_cross_business_header_is_rejected_with_403(): void
    {
        $otherBusiness = $this->makeBusiness('audit-test-other');
        $this->makeUser($otherBusiness, 'other');

        $response = $this->withHeaders(['X-Business-ID' => $otherBusiness->id])
            ->getJson('/api/v1/products');

        $response->assertStatus(403);
    }

    public function test_fiscal_year_close_posts_balanced_closing_entry(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $sale->assertStatus(201);

        $fiscalYear = FiscalYear::create([
            'business_id' => $this->business->id,
            'name' => 'Audit FY',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_closed' => false,
        ]);

        $close = $this->postJson("/api/v1/fiscal-years/{$fiscalYear->id}/close");
        $close->assertStatus(200);
        $close->assertJsonPath('is_closed', true);

        $closingEntries = $this->entriesOfType('fiscal_year');
        $this->assertCount(1, $closingEntries);

        $closing = $closingEntries->first();
        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $closing->id)
            ->get();

        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);
        $this->assertSame($credit, $debit);
        $this->assertGreaterThan(0, $debit);

        // Revenue account zeroed (debited), expense account zeroed (credited),
        // retained earnings receives the balancing leg.
        $this->assertGreaterThan(0, $this->lineBalance($closing->id, $this->accountId('4010')));
        $this->assertLessThan(0, $this->lineBalance($closing->id, $this->accountId('5010')));
        $this->assertNotSame(0, $this->lineBalance($closing->id, $this->accountId('3020')));
    }

    private function lineBalance(int $entryId, ?int $accountId): float
    {
        if (! $accountId) {
            return 0;
        }

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

    public function test_invoice_update_recomputes_net_amount_with_tax_discount_shipping(): void
    {
        $invoice = $this->postJson('/api/v1/invoices', [
            'items' => [
                [
                    'name' => 'Item A',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $invoice->assertStatus(201);
        $invoiceId = $invoice->json('id');
        $this->assertSame('21.00', $invoice->json('net_amount'));

        $update = $this->patchJson("/api/v1/invoices/{$invoiceId}", [
            'items' => [
                [
                    'name' => 'Item A',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
            'discount_amount' => 2,
            'shipping_amount' => 3,
        ]);
        $update->assertStatus(200);

        // subtotal 20 + tax 1 - discount 2 + shipping 3 = 22
        $this->assertSame('22.00', $update->json('net_amount'));
    }

    public function test_invoice_update_rejects_void_status(): void
    {
        $invoice = $this->postJson('/api/v1/invoices', [
            'items' => [['name' => 'Item A', 'quantity' => 1, 'unit_price' => 10]],
        ]);
        $invoice->assertStatus(201);

        $response = $this->patchJson("/api/v1/invoices/{$invoice->json('id')}", [
            'status' => 'void',
        ]);
        $response->assertStatus(422);
    }

    public function test_invoice_void_restores_batch_stock_and_refunds_payment(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 10);

        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $sale->assertStatus(201);
        $invoiceId = $sale->json('id');

        $this->assertSame('2.00', $batch->fresh()->quantity_sold);
        $this->assertSame('8.00', $product->fresh()->stock_quantity);

        $void = $this->postJson("/api/v1/invoices/{$invoiceId}/void");
        $void->assertStatus(200);
        $void->assertJsonPath('status', 'void');

        // Stock fully restored and a StockMovement recorded the addition.
        $this->assertSame('0.00', $batch->fresh()->quantity_sold);
        $this->assertSame('10.00', $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'invoice_void',
            'reference_id' => $invoiceId,
            'type' => 'addition',
        ]);
    }

    public function test_grn_receive_rejects_already_received_purchase_order(): void
    {
        $product = $this->makeProduct();
        $supplier = Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Audit Supplier',
            'is_active' => true,
        ]);
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-AUDIT-1',
            'status' => 'ordered',
            'total_amount' => 20,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => 'Audit Product',
            'quantity' => 5,
            'unit_cost' => 4,
            'total' => 20,
        ]);

        $payload = [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'batch_number' => 'GRN-AUDIT-1',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                    'total_cost' => 20,
                ],
            ],
            'payment_method' => 'credit',
        ];

        $this->postJson('/api/v1/product-batches/grn-receive', $payload)->assertStatus(201);

        $again = $this->postJson('/api/v1/product-batches/grn-receive', $payload);
        $again->assertStatus(422);

        // No extra batch was created for the rejected re-receipt.
        $this->assertDatabaseCount('product_batches', 1);
        $this->assertSame('5.00', $product->fresh()->stock_quantity);
    }

    public function test_product_batch_destroy_blocked_with_remaining_stock(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 5);

        $response = $this->deleteJson("/api/v1/product-batches/{$batch->id}");
        $response->assertStatus(422);

        $this->assertNotNull($batch->fresh());
    }

    public function test_product_batch_update_cannot_go_below_committed_quantity(): void
    {
        $product = $this->makeProduct();
        $batch = $this->makeBatch($product, 5);

        $sell = $this->postJson("/api/v1/product-batches/{$batch->id}/sell", ['quantity' => 2]);
        $sell->assertStatus(200);
        $this->assertSame('2.00', $batch->fresh()->quantity_sold);

        $response = $this->patchJson("/api/v1/product-batches/{$batch->id}", ['quantity' => 1]);
        $response->assertStatus(422);
        $this->assertSame('5.00', $batch->fresh()->quantity);
    }

    public function test_auto_price_preserves_manually_set_price(): void
    {
        $product = $this->makeProduct([
            'has_batch' => false,
            'price' => 10,
            'cost' => 4,
            'stock_quantity' => 5,
        ]);

        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ],
            ],
        ]);
        $sale->assertStatus(201);

        $this->assertSame('10.00', $product->fresh()->price);
    }

    public function test_promotion_apply_does_not_consume_uses(): void
    {
        $this->business->settings = ['promotions_enabled' => true];
        $this->business->save();

        $promotion = Promotion::create([
            'business_id' => $this->business->id,
            'name' => '10% Off',
            'type' => 'percentage',
            'value' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'max_uses' => 100,
            'current_uses' => 0,
            'is_active' => true,
        ]);

        $payload = [
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'unit_price' => 100],
            ],
        ];

        $first = $this->postJson('/api/v1/promotions/apply', $payload);
        $first->assertStatus(200);
        $this->assertGreaterThan(0, (float) $first->json('total_discount'));

        $this->postJson('/api/v1/promotions/apply', $payload)->assertStatus(200);

        $this->assertSame(0, (int) $promotion->fresh()->current_uses);
    }

    public function test_shift_close_total_refunds_includes_refunded_payments(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $this->postJson('/api/v1/shifts/start', ['opening_balance' => 50])->assertStatus(201);

        $sale = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $sale->assertStatus(201);
        $invoiceId = $sale->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/void")->assertStatus(200);

        $shift = Shift::where('user_id', $this->user->id)->where('status', 'open')->first();
        $this->assertNotNull($shift);

        $close = $this->postJson("/api/v1/shifts/{$shift->id}/close", ['actual_cash' => 49.0]);
        $close->assertStatus(200);

        // The 21 JOD payment was refunded on void.
        $this->assertSame('21.00', $close->json('total_refunds'));
        $this->assertSame(0, (int) $close->json('total_sales'));
    }

    public function test_recipe_rejects_product_from_another_business(): void
    {
        $otherBusiness = $this->makeBusiness('audit-test-other');
        $otherUser = $this->makeUser($otherBusiness, 'other');

        $otherProduct = Product::create([
            'business_id' => $otherBusiness->id,
            'created_by' => $otherUser->id,
            'name' => 'Other Product',
            'sku' => 'OTHER-'.strtoupper(Str::random(6)),
            'price' => 5,
            'cost' => 2,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/restaurant/recipes', [
            'product_id' => $otherProduct->id,
            'name' => 'Cross tenant recipe',
            'ingredients' => [
                ['product_id' => $otherProduct->id, 'quantity' => 1, 'unit' => 'g'],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_customer_statement_includes_refunds(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Audit Customer',
        ]);

        $product = $this->makeProduct();
        $this->makeBatch($product, 10);

        $sale = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ]);
        $sale->assertStatus(201);

        $statement = $this->getJson("/api/v1/customers/{$customer->id}/statement");
        $statement->assertStatus(200);
        $this->assertSame(1, collect($statement->json('lines'))->where('type', 'payment')->count());

        $this->postJson("/api/v1/invoices/{$sale->json('id')}/void")->assertStatus(200);

        $after = $this->getJson("/api/v1/customers/{$customer->id}/statement");
        $after->assertStatus(200);

        $lines = collect($after->json('lines'));
        $this->assertSame(1, $lines->where('type', 'refund')->count());
        // Invoice 21 - payment 21 + refund 21 => net 21 due (goods returned).
        $this->assertSame(21.0, round((float) $after->json('balance'), 2));
    }

    public function test_bank_reconciliation_book_balance_is_as_of_statement_date(): void
    {
        $accounting = app(AccountingService::class);
        $accounting->ensureChartOfAccounts($this->business->id);

        $bankAccountId = $this->accountId('1020');

        $accounting->post($this->business->id, [
            'date' => '2026-01-05',
            'description' => 'Deposit before statement',
            'reference_type' => 'test',
            'reference_id' => 9001,
        ], [
            ['account_id' => $bankAccountId, 'debit' => 100],
            ['code' => '3010', 'credit' => 100],
        ]);

        $accounting->post($this->business->id, [
            'date' => '2026-03-05',
            'description' => 'Deposit after statement',
            'reference_type' => 'test',
            'reference_id' => 9002,
        ], [
            ['account_id' => $bankAccountId, 'debit' => 50],
            ['code' => '3010', 'credit' => 50],
        ]);

        $response = $this->postJson('/api/v1/bank-reconciliations', [
            'account_id' => $bankAccountId,
            'statement_date' => '2026-02-01',
            'statement_balance' => 100,
        ]);
        $response->assertStatus(201);

        // Only the January deposit (100) counts toward the as-of book balance.
        $this->assertSame(100.0, round((float) $response->json('book_balance'), 4));
        $this->assertCount(1, $response->json('lines'));
    }

    public function test_profit_and_loss_and_balance_sheet_accounts_are_json_arrays(): void
    {
        $accounting = app(AccountingService::class);
        $accounting->ensureChartOfAccounts($this->business->id);

        // Activity in non-adjacent revenue accounts (4010 & 4040) and
        // non-adjacent expense accounts (5020 & 5042). After filtering out
        // zero-activity accounts the collections keep non-contiguous keys,
        // which previously made json_encode emit JSON objects instead of
        // arrays (pnl.revenue.accounts.map is not a function in the UI).
        $accounting->post($this->business->id, [
            'date' => '2026-06-15',
            'description' => 'Sale A',
            'reference_type' => 'test',
            'reference_id' => 8001,
        ], [
            ['code' => '1040', 'debit' => 100],
            ['code' => '4010', 'credit' => 100],
        ]);

        $accounting->post($this->business->id, [
            'date' => '2026-06-16',
            'description' => 'Cash surplus',
            'reference_type' => 'test',
            'reference_id' => 8002,
        ], [
            ['code' => '1010', 'debit' => 20],
            ['code' => '4040', 'credit' => 20],
        ]);

        $accounting->post($this->business->id, [
            'date' => '2026-06-17',
            'description' => 'Waste expense',
            'reference_type' => 'test',
            'reference_id' => 8003,
        ], [
            ['code' => '5020', 'debit' => 10],
            ['code' => '1010', 'credit' => 10],
        ]);

        $accounting->post($this->business->id, [
            'date' => '2026-06-18',
            'description' => 'Cash shortage',
            'reference_type' => 'test',
            'reference_id' => 8004,
        ], [
            ['code' => '5042', 'debit' => 5],
            ['code' => '1010', 'credit' => 5],
        ]);

        $pnl = $this->getJson('/api/v1/reports/profit-and-loss?date_from=2026-06-01&date_to=2026-06-30');
        $pnl->assertStatus(200);

        $revenueAccounts = $pnl->json('revenue.accounts');
        $expenseAccounts = $pnl->json('expense.accounts');
        $this->assertTrue(array_is_list($revenueAccounts), 'revenue.accounts must serialize as a JSON array');
        $this->assertTrue(array_is_list($expenseAccounts), 'expense.accounts must serialize as a JSON array');
        $this->assertCount(2, $revenueAccounts);
        $this->assertSame('4010', $revenueAccounts[0]['code']);
        $this->assertSame('4040', $revenueAccounts[1]['code']);
        $this->assertCount(2, $expenseAccounts);
        $this->assertSame('5042', $expenseAccounts[1]['code']);
        $this->assertSame(120.0, round((float) $pnl->json('revenue.total'), 2));
        $this->assertSame(15.0, round((float) $pnl->json('expense.total'), 2));

        $bs = $this->getJson('/api/v1/reports/balance-sheet?date_to=2026-06-30');
        $bs->assertStatus(200);
        $this->assertTrue(array_is_list($bs->json('asset.accounts')), 'asset.accounts must serialize as a JSON array');
        $this->assertTrue(array_is_list($bs->json('liability.accounts')), 'liability.accounts must serialize as a JSON array');
        $this->assertTrue(array_is_list($bs->json('equity.accounts')), 'equity.accounts must serialize as a JSON array');
    }
}
