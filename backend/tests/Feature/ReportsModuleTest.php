<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsModuleTest extends TestCase
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
            'name' => 'Reports Test Retail',
            'slug' => 'reports-test-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'reports-test-user',
            'email' => 'reports-test@example.com',
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
            'name' => 'Report Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 0,
            'category' => 'Beverages',
            'unit' => 'pcs',
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 0,
        ], $overrides));
    }

    private function makeInvoice(Product $product, float $quantity = 2, float $unitPrice = 10, array $overrides = [], ?array $itemMetadata = null, array $itemOverrides = []): Invoice
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $invoice = Invoice::create(array_merge([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoice_number' => 'INV-'.strtoupper(Str::random(6)),
            'status' => 'paid',
            'payment_status' => 'paid',
            'net_amount' => round($quantity * $unitPrice, 2),
            'subtotal' => round($quantity * $unitPrice, 2),
        ], $overrides));

        if ($createdAt) {
            $invoice->created_at = $createdAt;
            $invoice->save();
        }

        InvoiceItem::create(array_merge([
            'business_id' => $this->business->id,
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'total' => round($quantity * $unitPrice, 2),
            'metadata' => $itemMetadata,
        ], $itemOverrides));

        return $invoice;
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Reports Test Supplier',
            'is_active' => true,
        ]);
    }

    private function makePo(Supplier $supplier, float $total, ?string $expectedDelivery = null, string $status = 'ordered'): PurchaseOrder
    {
        return PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-'.strtoupper(Str::random(6)),
            'status' => $status,
            'total_amount' => $total,
            'expected_delivery' => $expectedDelivery,
        ]);
    }

    private function makePoPayment(PurchaseOrder $po, float $amount, string $status = 'completed'): PurchaseOrderPayment
    {
        return PurchaseOrderPayment::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchase_order_id' => $po->id,
            'supplier_id' => $po->supplier_id,
            'payment_number' => 'P-'.strtoupper(Str::random(6)),
            'amount' => $amount,
            'method' => 'bank',
            'status' => $status,
        ]);
    }

    public function test_sales_summary_aggregates_revenue_quantity_cogs_and_margin(): void
    {
        $productA = $this->makeProduct(['name' => 'Cola', 'cost' => 3]);
        $productB = $this->makeProduct(['name' => 'Water', 'cost' => 1, 'category' => 'Beverages']);
        $this->makeInvoice($productA, 10, 5);
        $this->makeInvoice($productB, 4, 2);

        $response = $this->getJson('/api/v1/reports/sales-summary');

        $response->assertOk()
            ->assertJsonPath('summary.invoice_count', 2)
            ->assertJsonPath('summary.total_quantity', 14)
            ->assertJsonPath('summary.total_revenue', 58)
            ->assertJsonPath('summary.net_revenue', 58)
            ->assertJsonPath('summary.gross_revenue', 58)
            ->assertJsonPath('summary.tax_amount', 0)
            ->assertJsonPath('summary.total_cogs', 34)
            ->assertJsonPath('summary.gross_profit', 24)
            ->assertJsonPath('summary.gross_margin', 41.38);

        $response->assertJsonCount(2, 'by_product');
        $response->assertJsonCount(1, 'by_category');
        $response->assertJsonPath('by_category.0.category', 'Beverages');
        $response->assertJsonPath('by_category.0.revenue', 58);
    }

    public function test_sales_summary_uses_batch_deduction_cogs_and_excludes_void(): void
    {
        $product = $this->makeProduct(['cost' => 10]);
        $this->makeInvoice($product, 5, 20, [], [
            'deductions' => [
                ['batch_id' => 1, 'batch_number' => 'B1', 'quantity' => 5, 'unit_cost' => 3],
            ],
        ]);
        $this->makeInvoice($product, 1, 20, ['status' => 'void']);

        $response = $this->getJson('/api/v1/reports/sales-summary');

        $response->assertOk()
            ->assertJsonPath('summary.invoice_count', 1)
            ->assertJsonPath('summary.total_cogs', 15)
            ->assertJsonPath('summary.gross_profit', 85);
    }

    public function test_sales_summary_respects_date_range(): void
    {
        $product = $this->makeProduct();
        $this->makeInvoice($product, 2, 10, ['created_at' => now()->subDays(30)->toDateTimeString()]);
        $this->makeInvoice($product, 3, 10);

        $response = $this->getJson('/api/v1/reports/sales-summary?date_from='.now()->subDays(1)->toDateString());

        $response->assertOk()
            ->assertJsonPath('summary.invoice_count', 1)
            ->assertJsonPath('summary.total_quantity', 3);
    }

    public function test_sales_summary_separates_tax_and_matches_pnl_net_revenue(): void
    {
        $product = $this->makeProduct(['name' => 'Taxed Item', 'cost' => 4]);

        // Exclusive tax: 175 net + 16% (28) → 203 gross collected.
        $invoice = $this->makeInvoice($product, 1, 175, [
            'net_amount' => 203,
            'subtotal' => 175,
            'tax_amount' => 28,
        ], null, [
            'unit_price' => 175,
            'tax_rate' => 16,
            'tax_amount' => 28,
            'total' => 203,
        ]);

        $this->getJson('/api/v1/reports/sales-summary')
            ->assertOk()
            ->assertJsonPath('summary.net_revenue', 175)
            ->assertJsonPath('summary.tax_amount', 28)
            ->assertJsonPath('summary.gross_revenue', 203)
            ->assertJsonPath('summary.total_revenue', 175)
            ->assertJsonPath('summary.gross_profit', 171)
            ->assertJsonPath('summary.gross_margin', 97.71)
            ->assertJsonPath('by_product.0.revenue', 175);

        app(AccountingService::class)->postSaleEntry($this->business->id, $invoice->fresh(), $this->user->id);

        $pnl = $this->getJson('/api/v1/reports/profit-and-loss')->assertOk()->json();
        $this->assertEqualsWithDelta(175.0, (float) $pnl['revenue']['total'], 0.01, 'P&L net revenue matches Sales Summary net revenue');

        $tb = $this->getJson('/api/v1/reports/trial-balance')->assertOk()->json();
        $revenueRow = collect($tb['accounts'])->firstWhere('code', '4010');
        $this->assertNotNull($revenueRow, 'trial balance must include the 4010 revenue account');
        $this->assertEquals(175.0, round((float) $revenueRow['balance'], 2), 'trial balance 4010 matches Sales Summary net revenue');
    }

    public function test_stock_valuation_values_batch_and_simple_products(): void
    {
        $simple = $this->makeProduct(['cost' => 2, 'stock_quantity' => 50]);
        $batch = $this->makeProduct(['name' => 'Batch Item', 'cost' => 0, 'has_batch' => true, 'stock_quantity' => 0]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $batch->id,
            'batch_number' => 'B-'.strtoupper(Str::random(6)),
            'quantity' => 100,
            'quantity_sold' => 30,
            'quantity_returned' => 0,
            'cost_per_unit' => 1.5,
            'expiry_date' => now()->addMonths(3)->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/reports/stock-valuation');

        $response->assertOk()
            ->assertJsonPath('summary.product_count', 2)
            ->assertJsonPath('summary.total_units', 120)
            ->assertJsonPath('summary.total_value', 205);

        $response->assertJsonCount(2, 'products');
        $response->assertJsonPath('products.0.name', 'Batch Item');
        $response->assertJsonPath('products.0.quantity', 70);
        $response->assertJsonPath('products.0.value', 105);
    }

    public function test_stock_valuation_excludes_expired_batches(): void
    {
        $batch = $this->makeProduct(['name' => 'Perishable', 'cost' => 0, 'has_batch' => true, 'stock_quantity' => 0]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $batch->id,
            'batch_number' => 'EXP-'.strtoupper(Str::random(6)),
            'quantity' => 100,
            'quantity_sold' => 0,
            'quantity_returned' => 0,
            'cost_per_unit' => 2,
            'expiry_date' => now()->subDays(2)->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/reports/stock-valuation');

        $response->assertOk()
            ->assertJsonPath('summary.product_count', 0)
            ->assertJsonPath('summary.total_value', 0);
    }

    public function test_sales_summary_defaults_to_year_to_date_window(): void
    {
        $product = $this->makeProduct(['cost' => 2]);
        $this->makeInvoice($product, 4, 10, ['created_at' => now()->subDays(2)]);
        $this->makeInvoice($product, 4, 10, ['created_at' => now()->subYear(1)->startOfYear()->subDay()]);

        $response = $this->getJson('/api/v1/reports/sales-summary');

        $response->assertOk()
            ->assertJsonPath('summary.invoice_count', 1)
            ->assertJsonPath('summary.total_revenue', 40)
            ->assertJsonPath('date_from', now()->startOfYear()->toDateString())
            ->assertJsonPath('date_to', now()->toDateString());

        $allTime = $this->getJson('/api/v1/reports/sales-summary?date_from=2000-01-01');
        $allTime->assertOk()
            ->assertJsonPath('summary.invoice_count', 2)
            ->assertJsonPath('summary.total_revenue', 80);
    }

    public function test_supplier_aging_buckets_outstanding_and_due_dates(): void
    {
        $supplier = $this->makeSupplier();

        $current = $this->makePo($supplier, 100, now()->addDays(10)->toDateString());
        $this->makePoPayment($current, 40);

        $late30 = $this->makePo($supplier, 200, now()->subDays(15)->toDateString());
        $late60 = $this->makePo($supplier, 300, now()->subDays(45)->toDateString());
        $late90 = $this->makePo($supplier, 400, now()->subDays(90)->toDateString());

        $response = $this->getJson('/api/v1/reports/supplier-aging');

        $response->assertOk()
            ->assertJsonPath('total_outstanding', 960)
            ->assertJsonCount(1, 'suppliers')
            ->assertJsonPath('suppliers.0.orders_count', 4)
            ->assertJsonPath('suppliers.0.outstanding', 960)
            ->assertJsonPath('suppliers.0.buckets.current', 60)
            ->assertJsonPath('suppliers.0.buckets.d30', 200)
            ->assertJsonPath('suppliers.0.buckets.d60', 300)
            ->assertJsonPath('suppliers.0.buckets.d90', 400);

        $response->assertJsonCount(4, 'suppliers.0.orders');
        $response->assertJsonPath('suppliers.0.orders.0.days_past_due', -10);
        $response->assertJsonPath('suppliers.0.orders.1.days_past_due', 15);
    }

    public function test_supplier_aging_excludes_draft_cancelled_and_fully_paid(): void
    {
        $supplier = $this->makeSupplier();

        $this->makePo($supplier, 100, now()->toDateString(), 'draft');
        $this->makePo($supplier, 100, now()->toDateString(), 'cancelled');
        $paid = $this->makePo($supplier, 100, now()->subDays(5)->toDateString(), 'received');
        $this->makePoPayment($paid, 100);

        $response = $this->getJson('/api/v1/reports/supplier-aging');

        $response->assertOk()
            ->assertJsonPath('total_outstanding', 0)
            ->assertJsonCount(0, 'suppliers');
    }

    public function test_supplier_aging_orders_include_due_date_and_balance(): void
    {
        $supplier = $this->makeSupplier();
        $po = $this->makePo($supplier, 250, now()->addDays(7)->toDateString());
        $this->makePoPayment($po, 100);

        $response = $this->getJson('/api/v1/reports/supplier-aging');

        $response->assertOk()
            ->assertJsonPath('suppliers.0.orders.0.order_number', $po->order_number)
            ->assertJsonPath('suppliers.0.orders.0.due_date', now()->addDays(7)->toDateString())
            ->assertJsonPath('suppliers.0.orders.0.total', 250)
            ->assertJsonPath('suppliers.0.orders.0.paid', 100)
            ->assertJsonPath('suppliers.0.orders.0.balance', 150);
    }

    public function test_trial_balance_signs_balances_by_account_type_and_stays_balanced(): void
    {
        $accounting = app(AccountingService::class);
        $accounting->post($this->business->id, [
            'date' => now()->toDateString(),
            'description' => 'Opening test',
            'reference_type' => 'test_tb',
            'reference_id' => 1,
        ], [
            ['code' => '1030', 'debit' => 152.90],
            ['code' => '3010', 'credit' => 152.90],
        ]);

        $response = $this->getJson('/api/v1/reports/trial-balance');

        $response->assertOk()
            ->assertJsonPath('total_debit', 152.90)
            ->assertJsonPath('total_credit', 152.90)
            ->assertJsonPath('is_balanced', true);

        $rows = collect($response->json('accounts'))->keyBy('code');
        $this->assertSame(152.90, round((float) $rows['1030']['balance'], 2), 'asset balances stay debit-positive');
        $this->assertSame(152.90, round((float) $rows['3010']['balance'], 2), 'credit-type accounts invert to a positive balance');
    }

    public function test_balance_sheet_equity_total_matches_sign_normalized_lines_and_retained_earnings(): void
    {
        $accounting = app(AccountingService::class);
        $today = now()->toDateString();

        $accounting->post($this->business->id, [
            'date' => $today,
            'description' => 'Opening',
            'reference_type' => 'test_bs_open',
            'reference_id' => 2,
        ], [
            ['code' => '1030', 'debit' => 152.90],
            ['code' => '3010', 'credit' => 152.90],
        ]);

        $accounting->post($this->business->id, [
            'date' => $today,
            'description' => 'Sale',
            'reference_type' => 'test_bs_sale',
            'reference_id' => 3,
        ], [
            ['code' => '1040', 'debit' => 100.00],
            ['code' => '4010', 'credit' => 100.00],
        ]);

        $accounting->post($this->business->id, [
            'date' => $today,
            'description' => 'COGS',
            'reference_type' => 'test_bs_cogs',
            'reference_id' => 4,
        ], [
            ['code' => '5010', 'debit' => 40.00],
            ['code' => '1030', 'credit' => 40.00],
        ]);

        $response = $this->getJson('/api/v1/reports/balance-sheet?date_to='.$today);

        $response->assertOk()->assertJsonPath('is_balanced', true);

        $assetLines = collect($response->json('asset.accounts'))->sum(fn ($a) => (float) $a['balance']);
        $this->assertEqualsWithDelta((float) $response->json('asset.total'), $assetLines, 0.01, 'asset total equals its line sum');

        $equityLines = collect($response->json('equity.accounts'))->sum(fn ($a) => (float) $a['balance']);
        $this->assertEqualsWithDelta(
            (float) $response->json('equity.total') + (float) $response->json('equity.retained_earnings'),
            $equityLines,
            0.01,
            'liabilities+equity total equals its line sum'
        );

        foreach ($response->json('equity.accounts') as $account) {
            $this->assertGreaterThanOrEqual(0, (float) $account['balance'], "credit-type line {$account['code']} must not report a negative raw balance");
        }

        $retained = collect($response->json('equity.accounts'))->firstWhere('code', '--');
        $this->assertNotNull($retained, 'retained earnings must appear as a balance-sheet equity line');
        $this->assertSame(60.0, round((float) $retained['balance'], 2), 'retained earnings = revenue (100) - expenses (40)');
    }

    public function test_cash_flow_tracks_only_real_cash_accounts_by_source_category(): void
    {
        $accounting = app(AccountingService::class);
        $today = now()->toDateString();

        // Non-cash capital/inventory opening: must appear NOWHERE in the cash flow.
        $accounting->post($this->business->id, [
            'date' => $today,
            'description' => 'Opening stock',
            'reference_type' => 'opening_balance',
            'reference_id' => 5,
        ], [
            ['code' => '1030', 'debit' => 152.90],
            ['code' => '3010', 'credit' => 152.90],
        ]);

        // Real cash deposit from the owner: financing.
        $accounting->post($this->business->id, [
            'date' => $today,
            'description' => 'Opening cash',
            'reference_type' => 'opening_balance',
            'reference_id' => 6,
        ], [
            ['code' => '1005', 'debit' => 200.00],
            ['code' => '3010', 'credit' => 200.00],
        ]);

        // Customer cash collection: operating.
        $accounting->post($this->business->id, [
            'date' => $today,
            'description' => 'Payment',
            'reference_type' => 'invoice_payment',
            'reference_id' => 7,
        ], [
            ['code' => '1010', 'debit' => 100.00],
            ['code' => '1040', 'credit' => 100.00],
        ]);

        $data = $this->getJson('/api/v1/reports/cash-flow')
            ->assertOk()
            ->json();

        $this->assertEqualsWithDelta(100.0, (float) $data['operating'], 0.001, 'only the cash collection counts as operating');
        $this->assertEqualsWithDelta(0.0, (float) $data['investing'], 0.001, 'no non-cash capital movement may surface as investing');
        $this->assertEqualsWithDelta(200.0, (float) $data['financing'], 0.001, 'owner cash deposit is financing');
        $this->assertEqualsWithDelta(300.0, (float) $data['net_cash_flow'], 0.001);
    }
}
