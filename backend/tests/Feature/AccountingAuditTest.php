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
use App\Models\User;
use App\Scopes\BusinessScope;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Accounting audit regression suite (W2 review round).
 *
 * Covers the genuine flaws found during the accounting-module audit:
 *  1. Invoice edits can no longer drift payment_status away from GL/AR truth
 *     (payload status is recomputed from completed payments).
 *  2. Manual journal-entry lines reject other-business account ids.
 *  3. System-critical accounts are immutable in code/type; parent links and
 *     type flips that would corrupt balances are blocked.
 *  4. Deleting an inventory adjustment reverses its posted GL entries and
 *     restores the batch/product stock it moved.
 *  5. General-ledger report scope account lookups to the requesting business.
 */
class AccountingAuditTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    private Business $foreignBusiness;

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
            'name' => 'Audit Retail',
            'slug' => 'audit-retail',
            'status' => 'active',
            'settings' => ['allow_credit_sales' => true],
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Test User',
            'username' => 'audit-user',
            'email' => 'audit@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->foreignBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Foreign Retail',
            'slug' => 'foreign-retail',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
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
            'tax_rate' => 0,
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
            'name' => 'Audit Customer',
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
        $query = JournalEntryLine::withoutGlobalScope(BusinessScope::class)->where('journal_entry_id', $entryId);

        return round(
            (float) (clone $query)->where('account_id', $accountId)->sum('debit')
            - (float) (clone $query)->where('account_id', $accountId)->sum('credit'),
            2
        );
    }

    private function assertAllEntriesPostedAndBalanced(): void
    {
        $entries = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->get();

        foreach ($entries as $entry) {
            $this->assertTrue((bool) $entry->is_posted, "Entry {$entry->entry_number} must be posted.");
        }

        $debit = round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->sum('debit'), 2);
        $credit = round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->sum('credit'), 2);

        $this->assertSame($credit, $debit, 'Ledger must balance globally.');
    }

    public function test_invoice_update_cannot_flip_payment_status_without_a_payment(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 10]);

        // Unpaid credit sale: sale entry only, Dr 1040 outstanding at 20.
        $created = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_status' => 'unpaid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ],
            ],
        ])->assertCreated()->json();

        $this->assertSame('unpaid', $created['payment_status']);
        $this->assertCount(0, $this->entriesOfType('invoice_payment'));
        $saleEntry = $this->entriesOfType('sale')->first();
        $this->assertSame(20.0, $this->lineBalanceOf($saleEntry->id, $this->accountId('1040')));

        // Editing with a stale "paid" payload must NOT flip the status or
        // create a phantom payment entry.
        $updated = $this->putJson('/api/v1/invoices/'.$created['id'], [
            'payment_status' => 'paid',
            'notes' => 'tried to mark paid via edit',
        ])->assertOk()->json();

        $this->assertSame('unpaid', $updated['payment_status']);
        $this->assertCount(0, $this->entriesOfType('invoice_payment'));

        // The receivable must remain outstanding on the books.
        $this->assertSame(20.0, $this->lineBalanceOf($saleEntry->id, $this->accountId('1040')));
        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_invoice_update_recomputes_payment_status_after_item_edits(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 10]);

        $paid = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ],
            ],
        ])->assertCreated()->json();

        $this->assertSame('paid', $paid['payment_status']);
        $this->assertCount(1, $this->entriesOfType('invoice_payment'));

        // Items grow to 30 JOD but only 20 JOD was ever collected. The edit
        // sends the stale "paid" flag — the backend must recompute to partial.
        $updated = $this->putJson('/api/v1/invoices/'.$paid['id'], [
            'payment_status' => 'paid',
            'items' => [
                [
                    'product_id' => $product->id,
                    'name' => 'Audit Product',
                    'quantity' => 3,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ],
            ],
        ])->assertOk()->json();

        $this->assertSame('partial', $updated['payment_status']);
        $this->assertSame(30.0, (float) $updated['net_amount']);

        // Only the original cash payment exists; no auto-status payment was created.
        $this->assertCount(1, $this->entriesOfType('invoice_payment'));

        // The sale entry was reposted for the new net: the original 20 remains
        // as a historical record next to its void_sale mirror, plus a fresh
        // sale entry at the new 30 figure.
        $saleEntries = $this->entriesOfType('sale');
        $this->assertCount(2, $saleEntries);
        $latestSale = $saleEntries->sortByDesc('id')->first();
        $this->assertSame(30.0, $this->lineBalanceOf($latestSale->id, $this->accountId('1040')));
        $this->assertSame(-30.0, $this->lineBalanceOf($latestSale->id, $this->accountId('4010')));

        $reversals = $this->entriesOfType('void_sale');
        $this->assertCount(1, $reversals);
        $this->assertSame(-20.0, $this->lineBalanceOf($reversals->first()->id, $this->accountId('1040')));

        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_journal_entry_lines_reject_foreign_business_accounts(): void
    {
        $foreignAccount = Account::create([
            'business_id' => $this->foreignBusiness->id,
            'code' => '1101',
            'name' => 'Foreign Asset',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $localAccount = Account::create([
            'business_id' => $this->business->id,
            'code' => '1101',
            'name' => 'Local Asset',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/journal-entries', [
            'date' => now()->toDateString(),
            'description' => 'Cross-tenant attempt',
            'is_posted' => true,
            'lines' => [
                ['account_id' => $localAccount->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $foreignAccount->id, 'debit' => 0, 'credit' => 100],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)->count());

        // Draft entry updates are equally protected.
        $draft = $this->postJson('/api/v1/journal-entries', [
            'date' => now()->toDateString(),
            'description' => 'Local draft',
            'is_posted' => false,
            'lines' => [
                ['account_id' => $localAccount->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $localAccount->id, 'debit' => 0, 'credit' => 50],
            ],
        ])->assertCreated()->json();

        $this->putJson('/api/v1/journal-entries/'.$draft['id'], [
            'lines' => [
                ['account_id' => $localAccount->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $foreignAccount->id, 'debit' => 0, 'credit' => 50],
            ],
        ])->assertStatus(422);

        $this->assertSame(
            0,
            JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('journal_entry_id', $draft['id'])
                ->where('account_id', $foreignAccount->id)
                ->count()
        );
    }

    public function test_system_account_code_and_type_are_immutable(): void
    {
        app(AccountingService::class)->ensureChartOfAccounts($this->business->id);

        $drawerId = $this->accountId('1010');
        $this->assertNotNull($drawerId);

        $this->putJson('/api/v1/accounts/'.$drawerId, ['code' => '1999'])
            ->assertStatus(422);

        $this->putJson('/api/v1/accounts/'.$drawerId, ['type' => 'liability'])
            ->assertStatus(422);

        $renamed = $this->putJson('/api/v1/accounts/'.$drawerId, ['name' => 'Audit Drawer'])
            ->assertOk()->json();
        $this->assertSame('Audit Drawer', $renamed['name']);
        $this->assertSame('1010', $renamed['code']);
    }

    public function test_account_type_is_locked_once_posted_and_parent_is_scoped(): void
    {
        app(AccountingService::class)->ensureChartOfAccounts($this->business->id);

        $foreignAccount = Account::create([
            'business_id' => $this->foreignBusiness->id,
            'code' => '1101',
            'name' => 'Foreign Asset',
            'type' => 'asset',
            'is_active' => true,
        ]);

        // Store with a foreign parent is rejected.
        $this->postJson('/api/v1/accounts', [
            'code' => '1105',
            'name' => 'Local Asset',
            'type' => 'asset',
            'parent_id' => $foreignAccount->id,
        ])->assertStatus(422);

        $local = Account::create([
            'business_id' => $this->business->id,
            'code' => '1105',
            'name' => 'Local Asset',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $revenue = Account::create([
            'business_id' => $this->business->id,
            'code' => '4100',
            'name' => 'Local Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        // Type change on an already-posted account is blocked.
        $this->postJson('/api/v1/journal-entries', [
            'date' => now()->toDateString(),
            'description' => 'Seeding 1105',
            'is_posted' => true,
            'lines' => [
                ['account_id' => $local->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 50],
            ],
        ])->assertCreated();

        $this->putJson('/api/v1/accounts/'.$local->id, ['type' => 'liability'])
            ->assertStatus(422);

        // Update with a foreign parent is rejected too.
        $this->putJson('/api/v1/accounts/'.$local->id, ['parent_id' => $foreignAccount->id])
            ->assertStatus(422);

        // A same-business parent is allowed.
        $this->putJson('/api/v1/accounts/'.$local->id, ['parent_id' => $this->accountId('1010')])
            ->assertOk();
    }

    public function test_delete_waste_adjustment_reverses_gl_and_restores_batch_stock(): void
    {
        $product = $this->makeProduct();
        $this->makeBatch($product, 10, 4.0);

        $created = $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'batch_id' => ProductBatch::where('product_id', $product->id)->first()->id,
            'type' => 'waste',
            'quantity' => 3,
            'unit_cost' => 4,
            'notes' => 'Spoiled',
        ])->assertCreated()->json();

        $adjustmentId = $created['id'];
        $this->assertSame(7.0, (float) $product->fresh()->stock_quantity);

        $entry = $this->entriesOfType('inventory_adjustment')->first();
        $this->assertSame(12.0, $this->lineBalanceOf($entry->id, $this->accountId('5020')));
        $this->assertSame(-12.0, $this->lineBalanceOf($entry->id, $this->accountId('1030')));

        $this->deleteJson('/api/v1/inventory-adjustments/'.$adjustmentId)->assertOk();

        // Stock restored to pre-adjustment position.
        $batch = ProductBatch::where('product_id', $product->id)->first();
        $this->assertSame(0.0, (float) $batch->quantity_sold);
        $this->assertSame(10.0, (float) $product->fresh()->stock_quantity);

        // The posted entry is mirrored exactly in the negative.
        $reversals = $this->entriesOfType('reversal_inventory_adjustment');
        $this->assertCount(1, $reversals);
        $this->assertSame((int) $adjustmentId, (int) $reversals->first()->reference_id);
        $this->assertSame(-12.0, $this->lineBalanceOf($reversals->first()->id, $this->accountId('5020')));
        $this->assertSame(12.0, $this->lineBalanceOf($reversals->first()->id, $this->accountId('1030')));

        $this->assertSoftDeleted('inventory_adjustments', ['id' => $adjustmentId]);
        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_delete_count_deficit_restores_simple_product_stock(): void
    {
        $product = $this->makeProduct(['has_batch' => false, 'stock_quantity' => 10]);

        $created = $this->postJson('/api/v1/inventory-adjustments', [
            'product_id' => $product->id,
            'type' => 'count_deficit',
            'quantity' => 4,
            'unit_cost' => 4,
            'notes' => 'Count mismatch',
        ])->assertCreated()->json();

        $adjustmentId = $created['id'];
        $this->assertSame(6.0, (float) $product->fresh()->stock_quantity);

        $entry = $this->entriesOfType('inventory_adjustment')->first();
        $this->assertSame(16.0, $this->lineBalanceOf($entry->id, $this->accountId('5030')));
        $this->assertSame(-16.0, $this->lineBalanceOf($entry->id, $this->accountId('1030')));

        $this->deleteJson('/api/v1/inventory-adjustments/'.$adjustmentId)->assertOk();

        $this->assertSame(10.0, (float) $product->fresh()->stock_quantity);

        $reversals = $this->entriesOfType('reversal_inventory_adjustment');
        $this->assertCount(1, $reversals);
        $this->assertSame(-16.0, $this->lineBalanceOf($reversals->first()->id, $this->accountId('5030')));
        $this->assertSame(16.0, $this->lineBalanceOf($reversals->first()->id, $this->accountId('1030')));

        $this->assertSoftDeleted('inventory_adjustments', ['id' => $adjustmentId]);
        $this->assertAllEntriesPostedAndBalanced();
    }

    public function test_general_ledger_report_rejects_foreign_account(): void
    {
        $foreignAccount = Account::create([
            'business_id' => $this->foreignBusiness->id,
            'code' => '1101',
            'name' => 'Foreign Asset',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/reports/general-ledger?account_id='.$foreignAccount->id)
            ->assertStatus(422);
    }
}
