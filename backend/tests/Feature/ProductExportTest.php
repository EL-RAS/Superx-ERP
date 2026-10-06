<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /products/export contract.
 *
 * The route is registered ahead of GET /products/{product}, so a 200 with a
 * spreadsheet body also proves the literal path wins over the wildcard binding
 * (otherwise the request would 404/422 inside the model binding on the literal
 * string "export").
 */
class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'sales'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Export Retail',
            'slug' => 'export-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Export Admin',
            'username' => 'export-admin',
            'email' => 'export-admin@example.com',
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
            'name' => 'Whole Milk 1L',
            'sku' => 'DAIRY-001',
            'barcode' => '6291041500011',
            'category' => 'Dairy',
            'cost' => 0.75,
            'price' => 1.25,
            'tax_rate' => 0,
            'unit' => 'pcs',
            'stock_quantity' => 42,
            'is_active' => true,
        ], $overrides));
    }

    /** @return array{entries: list<string>, rows: list<list<string>>} */
    private function readXlsx(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sx_test_');
        file_put_contents($path, $xlsx);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Exported file is not a readable zip archive.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertIsString($sheet, 'The workbook has no xl/worksheets/sheet1.xml part.');

        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml, 'The worksheet XML does not parse.');

        $rows = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $cells = [];
            foreach ($rowNode->c as $cell) {
                $type = (string) $cell['t'];
                $cells[] = $type === 'inlineStr' ? trim((string) $cell->is->t) : trim((string) $cell->v);
            }
            $rows[] = $cells;
        }

        return ['entries' => $entries, 'rows' => $rows];
    }

    public function test_export_returns_xlsx_containing_every_required_column(): void
    {
        $milkProduct = $this->makeProduct();
        $this->makeProduct([
            'name' => 'Stale Bread',
            'sku' => 'BAKE-009',
            'barcode' => '6291041500097',
            'category' => 'Bakery',
            'cost' => 0.4,
            'price' => 0.9,
            'stock_quantity' => 0,
            'is_active' => false,
        ]);

        $response = $this->get('/api/v1/products/export')->assertOk();

        $this->assertSame(self::XLSX_TYPE, $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename="products_export_'.now()->format('Y-m-d').'.xlsx"',
            $response->headers->get('Content-Disposition'),
        );

        $file = $this->readXlsx($response->getContent());

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertContains($part, $file['entries'], "Missing workbook part {$part}.");
        }

        $this->assertSame(
            ['ID', 'Name', 'SKU', 'Barcode', 'Category', 'Cost Price', 'Selling Price', 'Current Stock', 'Status', 'Created At'],
            $file['rows'][0],
        );

        // Header + two products.
        $this->assertCount(3, $file['rows']);

        // Export sorts by name, so "Stale Bread" lands on row 1.
        $stale = $file['rows'][1];
        $this->assertSame('Stale Bread', $stale[1]);
        $this->assertSame('BAKE-009', $stale[2]);
        $this->assertSame('6291041500097', $stale[3]);
        $this->assertSame('Bakery', $stale[4]);
        $this->assertSame('0.4', $stale[5]);
        $this->assertSame('0.9', $stale[6]);
        $this->assertSame('0', $stale[7]);
        $this->assertSame('Inactive', $stale[8]);

        $milk = $file['rows'][2];
        $this->assertSame('Whole Milk 1L', $milk[1]);
        $this->assertSame('Active', $milk[8]);
        $this->assertSame((string) $milkProduct->id, $milk[0]);
        $this->assertNotEmpty($milk[9], 'Created At must be populated.');
    }

    public function test_export_only_contains_products_of_the_logged_in_tenant(): void
    {
        $this->makeProduct(['name' => 'Local Coffee Beans', 'barcode' => '6291041500011']);

        $foreignType = BusinessType::create([
            'slug' => 'retail',
            'name_en' => 'Retail',
            'name_ar' => 'تجزئة',
            'allowed_modules' => ['inventory'],
        ]);
        $foreign = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $foreignType->id,
            'name' => 'Foreign Retail',
            'slug' => 'foreign-retail-export',
            'status' => 'active',
        ]);
        $foreignUser = User::create([
            'business_id' => $foreign->id,
            'name' => 'Foreign Admin',
            'username' => 'foreign-export-admin',
            'email' => 'foreign-export-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
        Product::create([
            'business_id' => $foreign->id,
            'created_by' => $foreignUser->id,
            'name' => 'Foreign Coffee Beans',
            'sku' => 'FOREIGN-001',
            'barcode' => '6291041500999',
            'category' => 'Beverages',
            'cost' => 2,
            'price' => 5,
            'tax_rate' => 0,
            'unit' => 'pcs',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $file = $this->readXlsx($this->get('/api/v1/products/export')->assertOk()->getContent());

        $names = array_column($file['rows'], 1);
        $this->assertContains('Local Coffee Beans', $names);
        $this->assertNotContains('Foreign Coffee Beans', $names);
        $this->assertCount(2, $file['rows']);
    }

    public function test_csv_format_returns_a_bom_prefixed_csv_download(): void
    {
        $this->makeProduct();

        $response = $this->get('/api/v1/products/export?format=csv')->assertOk();

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename="products_export_'.now()->format('Y-m-d').'.csv"',
            $response->headers->get('Content-Disposition'),
        );

        $body = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV needs a UTF-8 BOM for Excel.');

        $lines = explode("\n", trim(substr($body, 3)));
        $this->assertCount(2, $lines);

        // fputcsv encloses any field containing a space, so parse instead of
        // comparing the raw header line.
        $this->assertSame(
            ['ID', 'Name', 'SKU', 'Barcode', 'Category', 'Cost Price', 'Selling Price', 'Current Stock', 'Status', 'Created At'],
            str_getcsv($lines[0]),
        );
        $this->assertStringContainsString('Whole Milk 1L', $lines[1]);
    }

    public function test_unsupported_format_is_rejected(): void
    {
        $this->makeProduct();

        $this->getJson('/api/v1/products/export?format=pdf')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }

    public function test_export_is_denied_to_roles_without_inventory_view(): void
    {
        $this->makeProduct();

        $cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'Cashier',
            'username' => 'export-cashier',
            'email' => 'export-cashier@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/products/export')->assertForbidden();
    }
}
