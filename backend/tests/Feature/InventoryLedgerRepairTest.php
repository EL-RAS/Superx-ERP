<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\GoodsReceiptItem;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use App\Services\AccountingService;
use App\Services\InventoryValuationService;
use App\Services\LedgerRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the inventory valuation / COGS freeze work:
 *
 *  - non-batched stock is valued from its goods-receipt cost stack, not from
 *    "stock_quantity x product.cost";
 *  - a sale freezes the exact cost of the units that left the shelf on
 *    invoice_items.metadata['deductions'], and that frozen number is what the
 *    5010 COGS leg and ledger audit are measured against forever;
 *  - inventory:recalculate-ledger-assets audits and repairs historical drift
 *    with balanced delta entries, is idempotent, and posts nothing in dry-run;
 *  - the per-business inventory_costing_method switch flips batch picking
 *    between FEFO (expiry) and FIFO (receipt date).
 */
class InventoryLedgerRepairTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'purchases', 'reports'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Ledger Repair Retail',
            'slug' => 'ledger-repair-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'ledger-test-user',
            'email' => 'ledger-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->supplier = Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Cost Stack Supplier',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Cost Stack Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 5,
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 0,
        ], $overrides));
    }

    private function makeBatch(Product $product, float $quantity, float $costPerUnit, string $receivedDate, string $expiryDate): ProductBatch
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
            'received_date' => $receivedDate,
            'expiry_date' => $expiryDate,
            'is_active' => true,
        ]);
    }

    /**
     * A direct goods receipt - the only code path that writes the
     * goods_receipt_items rows the non-batched cost stack is built from.
     */
    private function directGrn(Product $product, float $quantity, float $unitCost): void
    {
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'credit',
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => $quantity, 'unit_cost' => $unitCost],
            ],
        ])->assertStatus(201);
    }

    private function sell(Product $product, float $quantity): Invoice
    {
        $response = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => 10,
                    'tax_rate' => 5,
                ],
            ],
        ])->assertStatus(201);

        return Invoice::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->findOrFail($response->json('id'));
    }

    private function accountId(string $code): int
    {
        return (int) Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

    /**
     * Debit minus credit of an account across every posted entry.
     */
    private function accountBalance(string $code): float
    {
        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($query) => $query
                ->withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('is_posted', true));

        return round((float) (clone $lines)->sum('debit') - (float) (clone $lines)->sum('credit'), 2);
    }

    /**
     * Net COGS sitting on 5010 for one invoice - the same netting the repair
     * service performs across sale / void_sale / reversal_sale / cogs_repair.
     */
    private function netPostedCogs(int $invoiceId): float
    {
        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('account_id', $this->accountId('5010'))
            ->whereHas('journalEntry', fn ($query) => $query
                ->withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('is_posted', true)
                ->where('reference_id', $invoiceId)
                ->whereIn('reference_type', LedgerRepairService::COGS_REFERENCE_TYPES));

        return round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);
    }

    private function entriesOfType(string $referenceType)
    {
        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', $referenceType)
            ->orderBy('id')
            ->get();
    }

    private function assertLedgerBalanced(): void
    {
        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();

        $this->assertSame(
            round((float) $lines->sum('credit'), 2),
            round((float) $lines->sum('debit'), 2),
        );
    }

    /**
     * Two receipts then one sale: the shelf holds 15 units worth 95 JOD, while
     * "stock x product.cost" would claim 90. The receipt-driven stack wins.
     */
    public function test_non_batched_stock_is_valued_from_its_goods_receipt_cost_stack(): void
    {
        $product = $this->makeProduct();
        $this->directGrn($product, 10, 5);
        $this->directGrn($product, 10, 7);

        $product = $product->fresh();
        $valuation = app(InventoryValuationService::class);

        // 20 units: 10 paid at 5 and 10 paid at 7.
        $this->assertSame(120.0, $valuation->productValue($this->business->id, $product));
        $this->assertSame(120.0, $valuation->stockValue($this->business->id));
        $this->assertSame(6.0, (float) $product->cost);

        $invoice = $this->sell($product, 5);
        $product = $product->fresh();

        // 15 units left: the 5 cheapest were sold (25), so 95 stays on the shelf.
        $this->assertSame(15.0, (float) $product->stock_quantity);
        $this->assertSame(95.0, $valuation->productValue($this->business->id, $product));
        $this->assertSame(95.0, $valuation->stockValue($this->business->id));

        // The old formula - a single blended cost times stock - would say 90.
        $this->assertNotSame(
            round((float) $product->stock_quantity * (float) $product->cost, 2),
            $valuation->productValue($this->business->id, $product),
        );

        // The stock-valuation report prints the very same figure the ledger is
        // reconciled to.
        $report = $this->getJson('/api/v1/reports/stock-valuation')->assertOk()->json();
        $this->assertSame(95.0, (float) $report['summary']['total_value']);

        // And the ledger agrees: 120 received - 25 sold at frozen cost.
        $this->assertSame(95.0, $this->accountBalance('1030'));
        $this->assertSame(25.0, $this->netPostedCogs($invoice->id));
    }

    /**
     * The units' cost is captured when they leave, never re-derived later.
     */
    public function test_sale_freezes_the_cost_stack_and_ignores_later_cost_changes(): void
    {
        $product = $this->makeProduct();
        $this->directGrn($product, 10, 5);
        $this->directGrn($product, 10, 7);

        $invoice = $this->sell($product->fresh(), 5);
        $invoice->load('items');

        $deductions = $invoice->items->first()->metadata['deductions'];
        $this->assertCount(1, $deductions);
        $this->assertNull($deductions[0]['batch_id']);
        $this->assertSame(5.0, (float) $deductions[0]['quantity']);
        $this->assertSame(5.0, (float) $deductions[0]['unit_cost']);

        $accounting = app(AccountingService::class);
        $this->assertSame(25.0, $accounting->invoiceCogs($invoice));
        $this->assertSame(25.0, $this->netPostedCogs($invoice->id));

        // A later cost change cannot reach back into a completed sale.
        $product->fresh()->update(['cost' => 99]);

        $invoice->refresh()->load('items');
        $this->assertSame(25.0, $accounting->invoiceCogs($invoice));

        $audit = app(LedgerRepairService::class)->audit($this->business->id);
        $this->assertSame(0, $audit['cogs']['invoices_with_drift']);
        $this->assertSame(0.0, $audit['cogs']['net_drift']);
    }

    /**
     * An invoice whose COGS legs are missing (or were booked at the wrong
     * cost) is corrected with one balanced delta entry, and a second run finds
     * nothing left to do.
     */
    public function test_repair_restores_missing_cogs_and_revalues_inventory_asset(): void
    {
        $product = $this->makeProduct();
        $this->directGrn($product, 10, 5);
        $this->directGrn($product, 10, 7);
        $invoice = $this->sell($product->fresh(), 5);

        // Simulate a legacy posting: the sale never recorded its COGS legs.
        $saleEntry = $this->entriesOfType('sale')->firstOrFail();
        JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $saleEntry->id)
            ->whereIn('account_id', [$this->accountId('5010'), $this->accountId('1030')])
            ->delete();

        $this->assertSame(0.0, $this->netPostedCogs($invoice->id));
        $this->assertSame(120.0, $this->accountBalance('1030'));

        $service = app(LedgerRepairService::class);

        $audit = $service->audit($this->business->id);
        $this->assertSame(1, $audit['cogs']['invoices_with_drift']);
        $this->assertSame(25.0, $audit['cogs']['net_drift']);
        $this->assertSame(95.0, $audit['stock_value']);
        // Inventory Asset still carries the receipt value (120) because the
        // sale never credited it away - the repair fixes both sides.
        $this->assertSame(-25.0, $audit['inventory']['delta']);

        // Dry audit leaves the ledger exactly as it was.
        $this->assertSame(0.0, $this->netPostedCogs($invoice->id));
        $this->assertSame(120.0, $this->accountBalance('1030'));

        $repair = $service->repair($this->business->id, $this->user->id);
        $this->assertSame(1, $repair['cogs']['invoices_with_drift']);
        $this->assertCount(1, $repair['cogs']['corrections']);
        $this->assertSame(25.0, $repair['cogs']['corrections'][0]['delta']);

        $this->assertSame(25.0, $this->netPostedCogs($invoice->id));
        $this->assertSame(95.0, $this->accountBalance('1030'));
        $this->assertSame(95.0, app(InventoryValuationService::class)->stockValue($this->business->id));
        $this->assertLedgerBalanced();

        $corrections = $this->entriesOfType('cogs_repair');
        $this->assertCount(1, $corrections);
        $this->assertSame($invoice->id, (int) $corrections->first()->reference_id);

        // Idempotent: a second pass posts nothing at all.
        $second = $service->repair($this->business->id, $this->user->id);
        $this->assertSame(0, $second['cogs']['invoices_with_drift']);
        $this->assertSame([], $second['cogs']['corrections']);
        $this->assertFalse($second['inventory']['changed']);
        $this->assertCount(1, $this->entriesOfType('cogs_repair'));
        $this->assertSame(95.0, $this->accountBalance('1030'));
        $this->assertLedgerBalanced();
    }

    /**
     * Audit mode reports the drift but never writes a journal entry.
     */
    public function test_repair_dry_run_reports_drift_without_posting(): void
    {
        $product = $this->makeProduct();
        $this->directGrn($product, 10, 5);
        $invoice = $this->sell($product->fresh(), 5);

        $saleEntry = $this->entriesOfType('sale')->firstOrFail();
        JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $saleEntry->id)
            ->whereIn('account_id', [$this->accountId('5010'), $this->accountId('1030')])
            ->delete();

        $before = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count();

        $audit = app(LedgerRepairService::class)->audit($this->business->id);

        $this->assertSame(1, $audit['cogs']['invoices_with_drift']);
        $this->assertSame(25.0, $audit['cogs']['net_drift']);
        $this->assertFalse($audit['inventory']['changed']);
        $this->assertSame(0.0, $this->netPostedCogs($invoice->id));
        $this->assertSame(
            $before,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->count(),
        );
        $this->assertCount(0, $this->entriesOfType('cogs_repair'));
    }

    /**
     * The command audits and repairs through the current connection, with a
     * dry-run that exits clean and posts nothing.
     */
    public function test_recalculate_ledger_assets_command_runs_and_repairs(): void
    {
        $product = $this->makeProduct();
        $this->directGrn($product, 10, 5);
        $invoice = $this->sell($product->fresh(), 5);

        $saleEntry = $this->entriesOfType('sale')->firstOrFail();
        JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $saleEntry->id)
            ->whereIn('account_id', [$this->accountId('5010'), $this->accountId('1030')])
            ->delete();

        $this->artisan('inventory:recalculate-ledger-assets', [
            '--dry-run' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertCount(0, $this->entriesOfType('cogs_repair'));

        $this->artisan('inventory:recalculate-ledger-assets', ['--force' => true])
            ->assertExitCode(0);

        $this->assertCount(1, $this->entriesOfType('cogs_repair'));
        $this->assertSame(25.0, $this->netPostedCogs($invoice->id));
        $this->assertSame(25.0, $this->accountBalance('1030'));
        $this->assertLedgerBalanced();

        // Running it again is a no-op.
        $this->artisan('inventory:recalculate-ledger-assets', ['--force' => true])
            ->assertExitCode(0);
        $this->assertCount(1, $this->entriesOfType('cogs_repair'));
    }

    /**
     * inventory_costing_method picks which batch leaves the shelf first:
     * FEFO (default) by expiry, FIFO by receipt date.
     */
    public function test_inventory_costing_method_switches_batch_picking_order(): void
    {
        $product = $this->makeProduct(['has_batch' => true]);
        $a = $this->makeBatch($product, 5, 4.0, now()->subDays(10)->toDateString(), now()->addMonths(6)->toDateString());
        $b = $this->makeBatch($product, 5, 4.5, now()->toDateString(), now()->addMonth()->toDateString());

        // Default: the batch expiring soonest goes first, even though it was
        // received later.
        $fefo = ProductBatch::getAvailableFefoBatches((int) $product->id, $this->business->id);
        $this->assertSame($b->id, (int) $fefo->first()->id);

        $this->business->update(['settings' => ['inventory_costing_method' => 'fifo']]);
        $this->assertSame('fifo', ProductBatch::costingMethod($this->business->id));

        $fifo = ProductBatch::getAvailableFefoBatches((int) $product->id, $this->business->id);
        $this->assertSame($a->id, (int) $fifo->first()->id);

        $this->business->update(['settings' => ['inventory_costing_method' => 'fefo']]);
        $this->assertSame('fefo', ProductBatch::costingMethod($this->business->id));
        $this->assertSame($b->id, (int) ProductBatch::getAvailableFefoBatches((int) $product->id, $this->business->id)->first()->id);
    }

    /**
     * Batch stock stays valued at exact batch cost, and the receipt lines a
     * batch product generates are recorded (they feed the audit trail).
     */
    public function test_batch_product_is_valued_from_live_batches(): void
    {
        $product = $this->makeProduct(['has_batch' => true, 'cost' => 4]);
        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'VAL-001',
            'quantity' => 10,
            'total_cost' => 40,
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ])->assertStatus(201);
        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'VAL-002',
            'quantity' => 5,
            'total_cost' => 30,
            'expiry_date' => now()->addMonths(9)->toDateString(),
        ])->assertStatus(201);

        $valuation = app(InventoryValuationService::class);
        $this->assertSame(70.0, $valuation->productValue($this->business->id, $product->fresh()));
        $this->assertSame(70.0, $valuation->stockValue($this->business->id));
        $this->assertSame(70.0, $this->accountBalance('1030'));

        $invoice = $this->sell($product->fresh(), 4);
        $invoice->load('items');

        // FEFO takes the soonest-expiring batch first, at its exact cost.
        $deductions = $invoice->items->first()->metadata['deductions'];
        $this->assertSame(16.0, round((float) array_sum(array_map(
            fn (array $d) => (float) $d['unit_cost'] * (float) $d['quantity'],
            $deductions,
        )), 2));

        $this->assertSame(54.0, $valuation->stockValue($this->business->id));
        $this->assertSame(54.0, $this->accountBalance('1030'));
        $this->assertSame(16.0, $this->netPostedCogs($invoice->id));

        $repair = app(LedgerRepairService::class)->audit($this->business->id);
        $this->assertSame(0, $repair['cogs']['invoices_with_drift']);
        $this->assertFalse($repair['inventory']['changed']);
    }

    /**
     * goods_receipt_items are what the non-batched stack is read from - the
     * rows must exist for a direct receipt or valuation silently degrades.
     */
    public function test_direct_goods_receipt_persists_cost_layers(): void
    {
        $product = $this->makeProduct();
        $this->directGrn($product, 10, 5);
        $this->directGrn($product, 10, 7);

        $layers = GoodsReceiptItem::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $layers);
        $this->assertSame(10.0, (float) $layers[0]->quantity);
        $this->assertSame(5.0, (float) $layers[0]->unit_cost);
        $this->assertSame(7.0, (float) $layers[1]->unit_cost);
        $this->assertSame(120.0, (float) $layers->sum('total'));
    }
}
