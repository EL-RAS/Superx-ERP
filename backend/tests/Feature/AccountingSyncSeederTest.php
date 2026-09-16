<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use App\Services\InventorySyncService;
use Database\Seeders\BusinessSeeder;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\OpeningBalanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bug-fix contract: the Opening Balance journal entry posted by the seeder
 * must reflect ONLY real seeded records — never hardcoded dummy balances.
 * Cash and Payables default to 0.00 when nothing is seeded; Inventory is
 * derived from seeded batches/products; Owner's Capital is the balancing leg.
 */
class AccountingSyncSeederTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private function makeBusiness(): Business
    {
        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Seeder Sync Co',
            'slug' => 'seeder-sync-co',
            'status' => 'active',
        ]);

        User::create([
            'business_id' => $this->business->id,
            'name' => 'Seed Admin',
            'email' => 'seed-admin@example.com',
            'username' => 'seed_admin',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        return $this->business;
    }

    /**
     * Seed milk (batch 100 × 2.00 = 200) + candy (simple 60 × 0.50 = 30)
     * → total inventory 230.00.
     */
    private function makeStock(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Milano Milk 1L',
            'sku' => 'SEED-MLK-001',
            'barcode' => '6221000001001',
            'price' => 32.50,
            'cost' => 2.00,
            'has_batch' => true,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'SEED-B-001',
            'quantity' => 100,
            'quantity_sold' => 0,
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'total_cost' => 200.00,
            'cost_per_unit' => 2.00,
            'is_active' => true,
        ]);

        $product->fresh()->recalculateStockQuantity();
        $product->fresh()->updateWeightedAverageCost();

        // Simple product with direct stock (no batches).
        Product::create([
            'business_id' => $this->business->id,
            'name' => 'Caramel Candy',
            'sku' => 'SEED-CND-002',
            'barcode' => '6221000001002',
            'price' => 5.00,
            'cost' => 0.50,
            'has_batch' => false,
            'stock_quantity' => 60,
            'is_active' => true,
        ]);
    }

    private function makeSupplier(): void
    {
        Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Seed Supplier',
            'contact_name' => 'Contact',
            'email' => 'supplier@seed.test',
            'phone' => '+962790000001',
            'address' => 'Seed St',
            'payment_terms' => 'Net 30',
            'is_active' => true,
        ]);
    }

    private function adminUserId(): int
    {
        return (int) User::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->firstOrFail()
            ->id;
    }

    private function makePurchaseOrder(string $status, float $total, ?float $paid = null): void
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'order_number' => 'SEEDPO-'.Str::upper(Str::random(6)),
            'status' => $status,
            'total_amount' => $total,
        ]);

        if ($paid !== null) {
            PurchaseOrderPayment::create([
                'business_id' => $this->business->id,
                'user_id' => $this->adminUserId(),
                'purchase_order_id' => $po->id,
                'payment_number' => 'SEEDPAY-'.Str::upper(Str::random(6)),
                'amount' => $paid,
                'method' => 'cash',
                'status' => 'completed',
            ]);
        }
    }

    private function makeCashDeposit(float $amount, bool $flagged): void
    {
        Payment::create([
            'business_id' => $this->business->id,
            'user_id' => $this->adminUserId(),
            'payment_number' => 'SEEDDEP-'.Str::upper(Str::random(6)),
            'amount' => $amount,
            'method' => 'cash',
            'status' => 'completed',
            'metadata' => $flagged ? ['opening_balance_deposit' => true] : [],
        ]);
    }

    private function runSeeders(): void
    {
        (new ChartOfAccountsSeeder)->run();
        (new OpeningBalanceSeeder)->run();
    }

    private function accountBalance(string $businessId, string $code): float
    {
        return (float) Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('code', $code)
            ->first()
            ->balance;
    }

    private function openingEntry(string $businessId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('reference_type', 'opening_balance')
            ->first();
    }

    public function test_opening_balance_reflects_only_real_inventory_and_no_dummy_balances(): void
    {
        $this->makeBusiness();
        $this->makeStock();

        $this->runSeeders();

        $expectedStock = round(
            app(InventorySyncService::class)->stockValue($this->business->id),
            2
        );

        // Inventory asset reflects the real seeded stock (milk 100 × 2.00 +
        // candy 60 × 0.50 = 230.00); cash + payables default to 0 (nothing
        // seeded), so owner capital = exactly the inventory.
        $this->assertSame(230.0, $expectedStock);
        $this->assertSame(230.0, $this->accountBalance($this->business->id, '1030'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '1005'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '2010'));
        $this->assertSame(230.0, $this->accountBalance($this->business->id, '3010'));

        $entry = $this->openingEntry($this->business->id);
        $this->assertNotNull($entry);

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entry->id)
            ->get();
        $this->assertSame(round($lines->sum('debit'), 2), round($lines->sum('credit'), 2));
        $this->assertSame(1, (int) $entry->is_posted);
    }

    public function test_opening_balance_is_idempotent(): void
    {
        $this->makeBusiness();
        $this->makeStock();

        $this->runSeeders();
        $this->runSeeders();

        $count = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'opening_balance')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_business_with_no_seeded_balances_gets_no_entry(): void
    {
        $this->makeBusiness();

        $this->runSeeders();

        $this->assertNull($this->openingEntry($this->business->id));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '1030'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '1005'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '2010'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '3010'));
    }

    public function test_opening_balance_entry_number_uses_open_prefix(): void
    {
        $this->makeBusiness();
        $this->makeStock();

        $this->runSeeders();

        $entry = $this->openingEntry($this->business->id);
        $this->assertStringStartsWith('OPEN-', $entry->entry_number);
    }

    public function test_opening_balance_sums_unpaid_purchase_orders_as_payables(): void
    {
        $this->makeBusiness();
        $this->makeSupplier();

        // Unpaid ordered PO → 100.00 payable.
        $this->makePurchaseOrder('ordered', 100.0);
        // Draft + cancelled POs never accrue a payable.
        $this->makePurchaseOrder('draft', 60.0);
        $this->makePurchaseOrder('cancelled', 40.0);
        // Ordered PO with 30.00 already paid → 20.00 payable.
        $this->makePurchaseOrder('ordered', 50.0, 30.0);

        $this->runSeeders();

        $this->assertSame(0.0, $this->accountBalance($this->business->id, '1030'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '1005'));
        // Payables = 100 + 20 = 120 → owner capital/equity is a −120 opening deficit.
        $this->assertSame(120.0, $this->accountBalance($this->business->id, '2010'));
        $this->assertSame(-120.0, $this->accountBalance($this->business->id, '3010'));

        $entry = $this->openingEntry($this->business->id);
        $this->assertNotNull($entry);

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entry->id)
            ->get();
        $this->assertSame(round($lines->sum('debit'), 2), round($lines->sum('credit'), 2));
    }

    public function test_opening_balance_counts_only_explicit_cash_deposits(): void
    {
        $this->makeBusiness();

        $this->makeCashDeposit(250.0, true);
        $this->makeCashDeposit(100.0, false);

        $this->runSeeders();

        // Only the flagged deposit is an opening cash balance; the unflagged
        // cash payment is ordinary sales cash and must not inflate it.
        $this->assertSame(250.0, $this->accountBalance($this->business->id, '1005'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '1030'));
        $this->assertSame(0.0, $this->accountBalance($this->business->id, '2010'));
        $this->assertSame(250.0, $this->accountBalance($this->business->id, '3010'));
    }

    public function test_real_business_seeder_opening_balance_uses_batch_inventory(): void
    {
        $this->seed([
            BusinessTypeSeeder::class,
            BusinessSeeder::class,
            ChartOfAccountsSeeder::class,
            OpeningBalanceSeeder::class,
        ]);

        $superRetail = Business::where('slug', 'super-retail')->firstOrFail();
        $fashionHub = Business::where('slug', 'fashion-hub')->firstOrFail();

        // Super Retail (biz1) batches: sold-out milk excluded, expired
        // bread/chicken batches excluded → remaining: bread 10×0.06 + chicken
        // 5×1.38 + rice 120×1.17 + pepsi 500×0.01 = 152.90. No POs/deposits
        // are seeded, so 1005 and 2010 must stay 0 — never 1000/600 dummies.
        $this->assertSame(152.90, round(
            app(InventorySyncService::class)->stockValue($superRetail->id),
            2
        ));
        $this->assertSame(152.90, $this->accountBalance($superRetail->id, '1030'));
        $this->assertSame(0.0, $this->accountBalance($superRetail->id, '1005'));
        $this->assertSame(0.0, $this->accountBalance($superRetail->id, '2010'));
        $this->assertSame(152.90, $this->accountBalance($superRetail->id, '3010'));

        $entry = $this->openingEntry($superRetail->id);
        $this->assertNotNull($entry);
        $this->assertStringStartsWith('OPEN-', $entry->entry_number);

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entry->id)
            ->get();
        $this->assertSame(2, $lines->count());
        $this->assertSame(round($lines->sum('debit'), 2), round($lines->sum('credit'), 2));

        // Fashion Hub (clothing) seeds no products/POs/deposits at the
        // inventory level → no opening entry at all.
        $this->assertNull($this->openingEntry($fashionHub->id));
    }
}
