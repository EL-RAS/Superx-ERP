<?php

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryAccounting;
use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use App\Services\InventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'import-store',
            'name_en' => 'Import Store',
            'name_ar' => 'متجر الاستيراد',
            'allowed_modules' => ['sales', 'pos', 'inventory'],
            'default_settings' => ['default_tax_rate' => 16],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Import Mart',
            'slug' => 'import-mart',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'username' => 'admin-'.Str::random(6),
            'email' => 'admin-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_primary_admin' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_csv_import_creates_new_products(): void
    {
        $csv = "barcode,name,cost_price,selling_price,stock_quantity,tax_rate,category\n"
            ."6291041500213,Apple,0.50,1.20,50,0,Produce\n"
            ."6291041500214,Banana,0.30,0.90,80,0,Produce\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $response = $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200);

        $response->assertJson([
            'created' => 2,
            'updated' => 0,
            'rows' => 2,
        ]);

        $apple = Product::where('barcode', '6291041500213')->first();
        $this->assertNotNull($apple);
        $this->assertSame('Apple', $apple->name);
        $this->assertSame('0.50', (string) $apple->cost);
        $this->assertSame('1.20', (string) $apple->price);
        $this->assertSame('50.00', (string) $apple->stock_quantity);
        $this->assertSame('Produce', $apple->category);
        $this->assertNotEquals('', $apple->sku);
        $this->assertTrue($apple->is_active);
        $this->assertSame($this->business->id, $apple->business_id);
    }

    public function test_csv_import_updates_existing_by_barcode_and_adds_stock(): void
    {
        $apple = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Old Apple',
            'barcode' => '6291041500213',
            'sku' => 'KEEP-SKU',
            'price' => 1.00,
            'cost' => 0.40,
            'tax_rate' => 0,
            'stock_quantity' => 10,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $apple->id,
            'batch_number' => 'SEED-1',
            'quantity' => 10,
            'cost_per_unit' => 0.40,
            'selling_price' => 1.00,
            'received_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $csv = "barcode,name,cost_price,selling_price,stock_quantity,tax_rate,category\n"
            ."6291041500213,Apple,0.60,1.50,5,5,Produce\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 0, 'updated' => 1]);

        $apple->refresh();
        $this->assertSame('KEEP-SKU', $apple->sku);
        $this->assertSame('1.50', (string) $apple->price);
        $this->assertSame('0.60', (string) $apple->cost);
        $this->assertSame('15.00', (string) $apple->stock_quantity);
        $this->assertSame(5, (int) $apple->tax_rate);

        // Imported stock lands in its own batch; FEFO sees 10 + 5 = 15.
        $this->assertSame(2, ProductBatch::where('product_id', $apple->id)->count());
        $this->assertStringContainsString(
            'IMP-INIT-',
            (string) ProductBatch::where('product_id', $apple->id)->latest('id')->value('batch_number'),
        );
        $this->assertEquals(15.0, ProductBatch::getAvailableFefoStock($apple->id, $this->business->id));
    }

    public function test_xlsx_import_parses_worksheet(): void
    {
        $file = $this->makeXlsx();

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 2]);

        $this->assertNotNull(Product::where('barcode', '6291041500213')->first());
        $this->assertNotNull(Product::where('barcode', '6291041500214')->first());
    }

    public function test_import_rejects_invalid_file_type(): void
    {
        $file = UploadedFile::fake()->create('products.pdf', 100, 'application/pdf');

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_csv_import_accepts_flexible_barcode_header_aliases(): void
    {
        // barcode aliases: "Code" and "SKU " (case/space-insensitive)
        $csv = "Code,name,cost_price,selling_price\n"
            ."6291041500213,Code Apple,0.50,1.20\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $this->assertSame('6291041500213', Product::where('name', 'Code Apple')->first()->barcode);
    }

    public function test_csv_import_accepts_sku_alias_and_preserves_plain_digits(): void
    {
        $csv = "SKU ,product_name,cost,retail_price\n"
            ."6291041500214,Sku Apple,0.50,1.20\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $this->assertSame('6291041500214', Product::where('name', 'Sku Apple')->first()->barcode);
    }

    public function test_csv_import_converts_scientific_notation_barcode_to_plain_digits(): void
    {
        $csv = "barcode,name,cost_price,selling_price\n"
            ."6.29104150021321E+12,Science Apple,0.50,1.20\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $this->assertSame('6291041500213', Product::where('name', 'Science Apple')->first()->barcode);
    }

    public function test_csv_import_converts_float_formatted_barcode_to_plain_digits(): void
    {
        $csv = "barcode,name,cost_price,selling_price\n"
            ."6291041500213.0,Float Apple,0.50,1.20\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $this->assertSame('6291041500213', Product::where('name', 'Float Apple')->first()->barcode);
    }

    public function test_xlsx_import_flexible_header_and_scientific_numeric_barcode(): void
    {
        $file = $this->makeXlsxWithAliasAndScientific();

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $this->assertSame('6291041500213', Product::where('name', 'Xlsx Sci')->first()->barcode);
    }

    public function test_csv_import_creates_initial_batch_for_stock(): void
    {
        $csv = "barcode,name,cost_price,selling_price,stock_quantity\n"
            ."6291041500213,Batch Apple,0.50,1.20,50\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1, 'updated' => 0]);

        $product = Product::where('barcode', '6291041500213')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->has_batch);

        $batch = ProductBatch::where('product_id', $product->id)->first();
        $this->assertNotNull($batch);
        $this->assertStringStartsWith('IMP-INIT-', $batch->batch_number);
        $this->assertSame('50.00', (string) $batch->quantity);
        $this->assertSame('0.50', (string) $batch->cost_per_unit);
        $this->assertSame('1.20', (string) $batch->selling_price);
        $this->assertTrue($batch->is_active);
        $this->assertSame(now()->toDateString(), $batch->received_date->toDateString());

        // POS FEFO must see exactly the imported quantity.
        $this->assertEquals(50.0, ProductBatch::getAvailableFefoStock($product->id, $this->business->id));
        $this->assertSame('50.00', (string) $product->stock_quantity);
    }

    public function test_csv_import_creates_no_batch_for_zero_stock(): void
    {
        $csv = "barcode,name,stock_quantity\n"
            ."6291041500214,No Stock Apple,0\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $product = Product::where('barcode', '6291041500214')->first();
        $this->assertNotNull($product);
        $this->assertSame('0.00', (string) $product->stock_quantity);
        $this->assertSame(0, ProductBatch::where('product_id', $product->id)->count());
    }

    public function test_imported_product_can_be_sold_at_pos(): void
    {
        $csv = "barcode,name,cost_price,selling_price,stock_quantity,tax_rate\n"
            ."6291041500213,Pos Apple,0.50,1.20,2,0\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200);

        $product = Product::where('barcode', '6291041500213')->first();
        $this->assertEquals(2.0, ProductBatch::getAvailableFefoStock($product->id, $this->business->id));

        // Register a POS sale of both imported units — this is the flow that
        // previously failed with "Insufficient batch stock ... Available: 0".
        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['product_id' => $product->id, 'name' => 'Pos Apple', 'quantity' => 2, 'unit_price' => 1.2, 'tax_rate' => 0]],
        ])->assertStatus(201);

        $this->assertEquals(0.0, ProductBatch::getAvailableFefoStock($product->id, $this->business->id));
        $this->assertSame('0.00', (string) $product->fresh()->stock_quantity);
    }

    public function test_quick_add_creates_product_with_stock_one(): void
    {
        $response = $this->postJson('/api/v1/products/quick-add', [
            'name' => 'New Snack',
            'barcode' => '6291041500999',
            'selling_price' => 3.50,
            'cost' => 1.25,
        ])->assertStatus(201);

        $product = $response->json();
        $this->assertSame('New Snack', $product['name']);
        $this->assertSame('3.50', $product['price']);
        $this->assertSame('1.00', (string) $product['stock_quantity']);
        $this->assertSame('16.00', (string) $product['tax_rate']);
        $this->assertNotEmpty($product['sku']);

        // The unit must be backed by an active batch or POS checkout fails.
        $batch = ProductBatch::where('product_id', $product['id'])->first();
        $this->assertNotNull($batch);
        $this->assertStringStartsWith('IMP-INIT-', $batch->batch_number);
        $this->assertSame('1.00', (string) $batch->quantity);
        $this->assertTrue($batch->is_active);
        $this->assertEquals(1.0, ProductBatch::getAvailableFefoStock($product['id'], $this->business->id));
    }

    public function test_quick_add_returns_existing_product_for_known_barcode(): void
    {
        $existing = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Known Item',
            'barcode' => '6291041500999',
            'sku' => 'EXIST',
            'price' => 2.00,
            'cost' => 1.00,
            'tax_rate' => 0,
            'stock_quantity' => 5,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        $this->postJson('/api/v1/products/quick-add', [
            'name' => 'Known Item',
            'barcode' => '6291041500999',
            'selling_price' => 9.99,
        ])->assertStatus(200)
            ->assertJsonPath('id', $existing->id);

        // A second row must never have been created.
        $this->assertSame(1, Product::where('barcode', '6291041500999')->count());
    }

    public function test_quick_add_requires_pos_or_inventory_create_permission(): void
    {
        Role::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'slug' => 'bystander',
            'name' => 'Bystander',
            'permissions' => [],
            'is_system' => false,
        ]);

        $restricted = User::create([
            'business_id' => $this->business->id,
            'name' => 'No Stock',
            'username' => 'no-'.Str::random(6),
            'email' => 'no-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'bystander',
            'is_active' => true,
        ]);

        Sanctum::actingAs($restricted);

        $this->postJson('/api/v1/products/quick-add', [
            'name' => 'Denied',
            'selling_price' => 1,
        ])->assertStatus(403);
    }

    public function test_csv_import_posts_opening_stock_journal_entry(): void
    {
        $csv = "barcode,name,cost_price,selling_price,stock_quantity\n"
            ."6291041500213,GL Apple,0.50,1.20,50\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        $product = Product::where('barcode', '6291041500213')->firstOrFail();
        $batch = ProductBatch::where('product_id', $product->id)->firstOrFail();

        // Imported opening stock is recognised on the ledger immediately:
        // Dr 1030 = quantity × cost, Cr 3010 (owner capital), one entry per
        // IMP-INIT batch, reference_type + batch id for idempotency.
        $entry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'import_opening_stock')
            ->where('reference_id', $batch->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(1, (int) $entry->is_posted);
        $this->assertSame('Import Opening Stock - Batch: '.$batch->batch_number, $entry->description);

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entry->id)
            ->get();

        $this->assertSame(2, $lines->count());
        $this->assertSame(25.0, (float) $lines->where('account_id', $this->accountId('1030'))->first()->debit);
        $this->assertSame(25.0, (float) $lines->where('account_id', $this->accountId('3010'))->first()->credit);
        $this->assertSame(
            round((float) $lines->sum('debit'), 2),
            round((float) $lines->sum('credit'), 2),
        );

        // The Inventory Asset account now holds exactly the imported value.
        $this->assertSame(25.0, $this->accountBalance('1030'));
        $this->assertSame(25.0, app(InventorySyncService::class)->stockValue($this->business->id));
    }

    public function test_csv_import_with_zero_cost_posts_no_entry(): void
    {
        $csv = "barcode,name,cost_price,stock_quantity\n"
            ."6291041500213,Free GL Apple,0,50\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 1]);

        // A zero-value batch creates no journal entry (nothing to recognise).
        $this->assertSame(
            0,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'import_opening_stock')
                ->count(),
        );
        $this->assertSame(0.0, $this->accountBalance('1030'));
    }

    public function test_csv_import_to_existing_product_posts_per_batch_entry(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Old Importer',
            'barcode' => '6291041500213',
            'sku' => 'IMP-EXIST',
            'price' => 1.00,
            'cost' => 0.40,
            'tax_rate' => 0,
            'stock_quantity' => 10,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'SEED-OLD',
            'quantity' => 10,
            'cost_per_unit' => 0.40,
            'total_cost' => 4.00,
            'selling_price' => 1.00,
            'received_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $csv = "barcode,name,cost_price,selling_price,stock_quantity\n"
            ."6291041500213,Old Importer,0.60,1.50,5\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJson(['created' => 0, 'updated' => 1]);

        // Exactly one new import entry for the new IMP-INIT batch (the seeded
        // legacy batch predates the feature and carries no entry).
        $entries = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'import_opening_stock')
            ->get();

        $this->assertCount(1, $entries);

        $batch = ProductBatch::where('product_id', $product->id)
            ->where('source_type', null)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame((int) $batch->id, (int) $entries->first()->reference_id);

        // The auto-reconcile safety net also closes the pre-existing legacy
        // drift (SEED-OLD 10 × 0.40 = 4.00 had no entry), so 1030 comes to
        // rest at the FULL real valuation: new batch 3.00 + legacy 4.00.
        $this->assertSame(5.0, (float) $batch->quantity);
        $this->assertSame(7.0, app(InventorySyncService::class)->stockValue($this->business->id));
        $this->assertSame(7.0, $this->accountBalance('1030'));
        $this->assertSame(
            1,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'inventory_opening')
                ->count(),
        );
    }

    public function test_quick_add_posts_opening_stock_journal_entry(): void
    {
        $this->postJson('/api/v1/products/quick-add', [
            'name' => 'GL Snack',
            'barcode' => '6291041500888',
            'selling_price' => 3.50,
            'cost' => 1.25,
        ])->assertStatus(201);

        $product = Product::where('name', 'GL Snack')->firstOrFail();
        $batch = ProductBatch::where('product_id', $product->id)->firstOrFail();

        $entry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'import_opening_stock')
            ->where('reference_id', $batch->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertStringStartsWith('Import Opening Stock - Batch: ', $entry->description);

        $lines = JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entry->id)
            ->get();

        $this->assertSame(1.25, (float) $lines->where('account_id', $this->accountId('1030'))->first()->debit);
        $this->assertSame(1.25, (float) $lines->where('account_id', $this->accountId('3010'))->first()->credit);
        $this->assertSame(1.25, $this->accountBalance('1030'));
    }

    public function test_imported_stock_parity_after_pos_sale_and_sync(): void
    {
        $csv = "barcode,name,cost_price,selling_price,stock_quantity,tax_rate\n"
            ."6291041500213,Parity Apple,0.50,1.20,10,0\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson('/api/v1/products/import', ['file' => $file])->assertStatus(200);

        // Post-import: 1030 == valuation.
        $this->assertSame(5.0, $this->accountBalance('1030'));
        $this->assertSame(5.0, app(InventorySyncService::class)->stockValue($this->business->id));

        // Sell 4 units at cost 0.50 → COGS Dr 5010 (2.00) / Cr 1030 (2.00).
        $product = Product::where('barcode', '6291041500213')->firstOrFail();
        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['product_id' => $product->id, 'name' => 'Parity Apple', 'quantity' => 4, 'unit_price' => 1.2, 'tax_rate' => 0]],
        ])->assertStatus(201);

        // Ledger tracks the exact remaining 6 × 0.50 = 3.00.
        $this->assertSame(3.0, $this->accountBalance('1030'));
        $this->assertSame(3.0, app(InventorySyncService::class)->stockValue($this->business->id));

        // The sync command finds nothing left to repair.
        $this->artisan('inventory:sync-accounting', ['--business' => $this->business->slug])
            ->assertExitCode(0);
        $this->assertSame(3.0, app(InventorySyncService::class)->stockValue($this->business->id));
        $this->assertSame(
            1,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'import_opening_stock')
                ->count(),
        );
    }

    public function test_inventory_sync_repairs_drifted_import_batches(): void
    {
        // A legacy batch that predates the auto-posting fix (no journal entry).
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Legacy Item',
            'barcode' => '6291041500877',
            'sku' => 'LEGACY',
            'price' => 2.00,
            'cost' => 0.50,
            'tax_rate' => 0,
            'stock_quantity' => 20,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'IMP-INIT-'.$product->id.'-1',
            'quantity' => 20,
            'cost_per_unit' => 0.50,
            'total_cost' => 10.00,
            'selling_price' => 2.00,
            'received_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $product->fresh()->recalculateStockQuantity();

        // Actual stock value 10.00, but 1030 sits at 0.00 (the import never
        // posted). The sync command closes the gap with a balanced entry.
        $this->assertSame(10.0, app(InventorySyncService::class)->stockValue($this->business->id));
        $this->assertSame(0.0, $this->accountBalance('1030'));

        $this->artisan('inventory:sync-accounting', ['--business' => $this->business->slug])
            ->assertExitCode(0);

        $this->assertSame(10.0, $this->accountBalance('1030'));
        $this->assertSame(
            10.0,
            (float) Account::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('code', '3010')
                ->firstOrFail()
                ->balance,
        );

        $syncEntry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'inventory_opening')
            ->first();
        $this->assertNotNull($syncEntry);
        $this->assertSame(1, (int) $syncEntry->is_posted);

        // Running again is a no-op (self-correcting delta math).
        $this->artisan('inventory:sync-accounting', ['--business' => $this->business->slug])
            ->assertExitCode(0);
        $this->assertSame(10.0, $this->accountBalance('1030'));
        $this->assertSame(
            1,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'inventory_opening')
                ->count(),
        );
    }

    public function test_bulk_import_dispatches_background_reconciliation_job(): void
    {
        Queue::fake();

        $csv = "barcode,name,cost_price,selling_price,stock_quantity,tax_rate\n"
            ."6291041500213,Trigger Apple,0.50,1.20,10,0\n";

        $this->postJson('/api/v1/products/import', [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ])->assertStatus(200);

        Queue::assertPushed(
            ReconcileInventoryAccounting::class,
            fn (ReconcileInventoryAccounting $job) => $job->businessId === $this->business->id
                && $job->userId === (int) $this->user->id,
        );
    }

    public function test_grn_approval_dispatches_background_reconciliation_job(): void
    {
        Queue::fake();

        $supplier = Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'GRN Supplier',
            'phone' => '+962788888888',
        ]);

        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'GRN Trigger Item',
            'sku' => 'GRN-TRIG',
            'price' => 3.00,
            'cost' => 2.00,
            'tax_rate' => 0,
            'stock_quantity' => 0,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-'.strtoupper(Str::random(6)),
            'status' => 'ordered',
            'total_amount' => 8,
        ]);

        $poItem = PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'name' => 'GRN Trigger Item',
            'quantity' => 1,
            'unit_cost' => 8,
            'total' => 8,
        ]);

        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $product->id,
                'received_quantity' => 1,
                'unit_cost' => 8,
                'expiry_date' => now()->addMonths(6)->toDateString(),
            ]],
        ])->assertStatus(201);

        Queue::assertPushed(
            ReconcileInventoryAccounting::class,
            fn (ReconcileInventoryAccounting $job) => $job->businessId === $this->business->id
                && $job->userId === (int) $this->user->id,
        );
    }

    public function test_manual_batch_store_reconciles_inventory_asset_in_real_time(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Manual Batch Item',
            'sku' => 'MANUAL-B',
            'price' => 2.00,
            'cost' => 1.00,
            'tax_rate' => 0,
            'stock_quantity' => 0,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        $this->postJson('/api/v1/product-batches', [
            'product_id' => $product->id,
            'batch_number' => 'MANUAL-1',
            'quantity' => 5,
            'total_cost' => 5,
            'source_type' => 'manual_entry',
        ])->assertStatus(201);

        // No background job is dispatched for a manual batch store: the 1030
        // balance is reconciled synchronously inside the request, so the
        // on-hand value is already mirrored onto the ledger.
        $this->assertSame(5.0, $this->accountBalance('1030'));
        $this->assertSame(5.0, app(InventorySyncService::class)->stockValue($this->business->id));
        $this->assertSame(
            0,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'inventory_opening')
                ->count(),
        );
    }

    public function test_background_reconciliation_job_closes_ledger_drift(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Drifted Item',
            'barcode' => '6291041500999',
            'sku' => 'DRIFT',
            'price' => 2.00,
            'cost' => 0.50,
            'tax_rate' => 0,
            'stock_quantity' => 20,
            'has_batch' => true,
            'is_active' => true,
            'min_stock' => 0,
            'unit' => 'pcs',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'DRIFT-1',
            'quantity' => 20,
            'cost_per_unit' => 0.50,
            'total_cost' => 10.00,
            'selling_price' => 2.00,
            'received_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $product->fresh()->recalculateStockQuantity();

        $this->assertSame(10.0, app(InventorySyncService::class)->stockValue($this->business->id));
        $this->assertSame(0.0, $this->accountBalance('1030'));

        $job = new ReconcileInventoryAccounting($this->business->id, (int) $this->user->id);
        $job->handle(app(InventorySyncService::class));

        $this->assertSame(10.0, $this->accountBalance('1030'));
        $this->assertSame(
            1,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'inventory_opening')
                ->count(),
        );

        // Idempotent: running the job again is a no-op.
        $job->handle(app(InventorySyncService::class));
        $this->assertSame(10.0, $this->accountBalance('1030'));
        $this->assertSame(
            1,
            JournalEntry::withoutGlobalScope(BusinessScope::class)
                ->where('business_id', $this->business->id)
                ->where('reference_type', 'inventory_opening')
                ->count(),
        );
    }

    public function test_manual_batch_quantity_reduction_posts_adjustment_and_reconciles(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Edit Batch Item',
            'sku' => 'EDIT-B',
            'price' => 4.00,
            'cost' => 2.00,
            'tax_rate' => 0,
            'stock_quantity' => 0,
            'has_batch' => true,
            'is_active' => true,
            'unit' => 'pcs',
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'EDIT-1',
            'quantity' => 10,
            'quantity_sold' => 0,
            'cost_per_unit' => 2.00,
            'total_cost' => 20.00,
            'source_type' => 'goods_receipt',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        // Establish the ledger: 10 units @ 2.00 = 20.00 on 1030.
        app(InventorySyncService::class)->sync($this->business->id, (int) $this->user->id);
        $this->assertSame(20.0, $this->accountBalance('1030'));

        // Manual edit cuts the batch to 8 units @ 2.00 = 16.00.
        $this->patchJson('/api/v1/product-batches/'.$batch->id, ['quantity' => 8])
            ->assertStatus(200);

        $adjustment = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'manual_batch_adjustment')
            ->latest('id')
            ->first();
        $this->assertNotNull($adjustment);
        $this->assertStringContainsString('Manual Batch Adjustment - Batch: EDIT-1', $adjustment->description);
        $this->assertSame(-4.0, $this->lineBalanceOf($adjustment->id, $this->accountId('1030')));

        // Dr 5020 (expense) offset the 1030 credit, entry stays balanced, and
        // the inventory asset now mirrors the on-hand value exactly.
        $this->assertSame(4.0, $this->lineBalanceOf($adjustment->id, $this->accountId('5020')));
        $this->assertSame(16.0, $this->accountBalance('1030'));
        $this->assertSame(16.0, app(InventorySyncService::class)->stockValue($this->business->id));
    }

    public function test_manual_batch_quantity_zero_writes_off_batch_and_reconciles(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Write Off Batch Item',
            'sku' => 'WRITEOFF-B',
            'price' => 4.00,
            'cost' => 2.00,
            'tax_rate' => 0,
            'stock_quantity' => 0,
            'has_batch' => true,
            'is_active' => true,
            'unit' => 'pcs',
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'WRITEOFF-1',
            'quantity' => 10,
            'quantity_sold' => 0,
            'cost_per_unit' => 2.00,
            'total_cost' => 20.00,
            'source_type' => 'goods_receipt',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        app(InventorySyncService::class)->sync($this->business->id, (int) $this->user->id);
        $this->assertSame(20.0, $this->accountBalance('1030'));

        $this->patchJson('/api/v1/product-batches/'.$batch->id, ['quantity' => 0])
            ->assertStatus(200);

        $this->assertSame(0.0, $this->accountBalance('1030'));
        $this->assertSame(0.0, app(InventorySyncService::class)->stockValue($this->business->id));

        $adjustment = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'manual_batch_adjustment')
            ->latest('id')
            ->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(-20.0, $this->lineBalanceOf($adjustment->id, $this->accountId('1030')));
        $this->assertSame(20.0, $this->lineBalanceOf($adjustment->id, $this->accountId('5020')));
    }

    public function test_manual_batch_cost_increase_posts_debit_1030_and_reconciles(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Raise Cost Item',
            'sku' => 'RAISE-B',
            'price' => 4.00,
            'cost' => 2.00,
            'tax_rate' => 0,
            'stock_quantity' => 0,
            'has_batch' => true,
            'is_active' => true,
            'unit' => 'pcs',
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'RAISE-1',
            'quantity' => 10,
            'quantity_sold' => 0,
            'cost_per_unit' => 2.00,
            'total_cost' => 20.00,
            'source_type' => 'goods_receipt',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        app(InventorySyncService::class)->sync($this->business->id, (int) $this->user->id);
        $this->assertSame(20.0, $this->accountBalance('1030'));

        // total_cost 20.00 -> 30.00 (quantity unchanged) revalues the batch.
        $this->patchJson('/api/v1/product-batches/'.$batch->id, ['total_cost' => 30])
            ->assertStatus(200);

        $adjustment = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'manual_batch_adjustment')
            ->latest('id')
            ->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(10.0, $this->lineBalanceOf($adjustment->id, $this->accountId('1030')));
        $this->assertSame(-10.0, $this->lineBalanceOf($adjustment->id, $this->accountId('3010')));

        $this->assertSame(30.0, $this->accountBalance('1030'));
        $this->assertSame(30.0, app(InventorySyncService::class)->stockValue($this->business->id));
    }

    public function test_manual_batch_reduction_stays_reconciled_without_double_posting(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Reconcile Batch Item',
            'sku' => 'RECON-B',
            'price' => 4.00,
            'cost' => 2.00,
            'tax_rate' => 0,
            'stock_quantity' => 0,
            'has_batch' => true,
            'is_active' => true,
            'unit' => 'pcs',
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'RECON-1',
            'quantity' => 10,
            'quantity_sold' => 0,
            'cost_per_unit' => 2.00,
            'total_cost' => 20.00,
            'source_type' => 'goods_receipt',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        app(InventorySyncService::class)->sync($this->business->id, (int) $this->user->id);
        $before = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count();

        $this->patchJson('/api/v1/product-batches/'.$batch->id, ['quantity' => 6])
            ->assertStatus(200);
        $afterReduction = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count();

        // One adjustment entry (no residual inventory_opening net because the
        // real-time sync inside the request finds a zero delta).
        $this->assertSame($before + 1, $afterReduction);

        $entriesAfterReduce = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'inventory_opening')
            ->count();

        $this->patchJson('/api/v1/product-batches/'.$batch->id, ['quantity' => 2])
            ->assertStatus(200);

        // Second reduction posts one more adjustment; the ledger still matches
        // on-hand value and no inventory_opening net was ever added.
        $this->assertSame($afterReduction + 1, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count());
        $this->assertSame($entriesAfterReduce, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'inventory_opening')
            ->count());
        $this->assertSame(4.0, $this->accountBalance('1030'));
        $this->assertSame(4.0, app(InventorySyncService::class)->stockValue($this->business->id));
    }

    private function accountId(string $code): int
    {
        return (int) Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

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

    private function lineBalanceOf(int $entryId, int $accountId): float
    {
        return round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $accountId)
            ->sum('debit')
            - (float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $accountId)
                ->sum('credit'), 2);
    }

    private function makeXlsx(): UploadedFile
    {
        $sheet = '<?xml version="1.0"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>barcode</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>name</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>cost_price</t></is></c>'
            .'<c r="D1" t="inlineStr"><is><t>selling_price</t></is></c>'
            .'<c r="E1" t="inlineStr"><is><t>stock_quantity</t></is></c>'
            .'<c r="F1" t="inlineStr"><is><t>tax_rate</t></is></c>'
            .'<c r="G1" t="inlineStr"><is><t>category</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>6291041500213</t></is></c>'
            .'<c r="B2" t="inlineStr"><is><t>Xlsx Apple</t></is></c>'
            .'<c r="C2" t="inlineStr"><is><t>0.50</t></is></c>'
            .'<c r="D2" t="inlineStr"><is><t>1.20</t></is></c>'
            .'<c r="E2" t="inlineStr"><is><t>50</t></is></c>'
            .'<c r="F2" t="inlineStr"><is><t>0</t></is></c>'
            .'<c r="G2" t="inlineStr"><is><t>Produce</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>6291041500214</t></is></c>'
            .'<c r="B3" t="inlineStr"><is><t>Xlsx Banana</t></is></c>'
            .'<c r="C3" t="inlineStr"><is><t>0.30</t></is></c>'
            .'<c r="D3" t="inlineStr"><is><t>0.90</t></is></c>'
            .'<c r="E3" t="inlineStr"><is><t>80</t></is></c>'
            .'<c r="F3" t="inlineStr"><is><t>0</t></is></c>'
            .'<c r="G3" t="inlineStr"><is><t>Produce</t></is></c></row>'
            .'</sheetData></worksheet>';

        return $this->makeXlsxFromSheet($sheet);
    }

    /**
     * xlsx using a "Code" barcode alias and a NUMERIC cell (no t attribute)
     * written in scientific notation — exactly how Excel stores long barcodes.
     */
    private function makeXlsxWithAliasAndScientific(): UploadedFile
    {
        $sheet = '<?xml version="1.0"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>Code</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>name</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>cost_price</t></is></c>'
            .'<c r="D1" t="inlineStr"><is><t>selling_price</t></is></c></row>'
            // numeric cell (no t="") in scientific notation
            .'<row r="2"><c r="A2"><v>6.29104150021321E+12</v></c>'
            .'<c r="B2" t="inlineStr"><is><t>Xlsx Sci</t></is></c>'
            .'<c r="C2"><v>0.5</v></c>'
            .'<c r="D2"><v>1.2</v></c></row>'
            .'</sheetData></worksheet>';

        return $this->makeXlsxFromSheet($sheet);
    }

    private function makeXlsxFromSheet(string $sheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlx').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $file = new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        return $file;
    }
}
