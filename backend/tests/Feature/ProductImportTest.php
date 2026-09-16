<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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

    /**
     * Build a minimal, valid .xlsx file backed by ZipArchive.
     * Uses an inline (t = "inlineStr") worksheet to avoid shared strings.
     */
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
